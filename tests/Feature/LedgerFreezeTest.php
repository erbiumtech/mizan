<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\JournalEntryService;
use App\Modules\Core\Models\CompanyModule;
use App\Support\TenantSettings;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Spatie\Permission\Models\Role;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * A period closed off without closing the year — `docs/erpnext-gap-plan.md` Phase 3.
 *
 * `fiscal_years.closed_at` freezes a whole year, so there was nothing between "open" and "closed" and the
 * ordinary month-end request could not be expressed: *stop backdating into July now that July is reported,
 * while the year stays open*. One setting and one guard.
 *
 * Two things are asserted beyond the obvious refusal, and both are what make the feature usable rather than
 * a nuisance:
 *
 *  - **a draft may still be written and re-dated** — the guard is at posting, exactly as the closed-year
 *    guard beside it is, so nothing stops somebody preparing a correction;
 *  - **there is an exemption, and it is a permission.** ERPNext carries one on both of its mechanisms, and
 *    without it the first real correction forces somebody to clear the date, post, and remember to set it
 *    back — a window open silently and recorded nowhere.
 */
class LedgerFreezeTest extends AccountingTestCase
{
    use InteractsWithTenant;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2027-02-19 09:00:00');

        $this->actingAs($this->makeUser('Accountant', 'freeze@test.local'));
        $this->setCurrentTenant();

        foreach (['accounting'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }

        modules()->flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** With no date set, nothing changes — which is every company that has not asked for this. */
    public function test_nothing_is_frozen_by_default(): void
    {
        $this->assertNull(setting('accounting.ledger_frozen_before'));

        $entry = $this->postEntry('2026-08-15');

        $this->assertTrue($entry->is_posted);
    }

    /** An entry dated before the frozen date is refused, and told what to do about it. */
    public function test_a_backdated_entry_is_refused(): void
    {
        $this->freezeBefore('2027-02-01');

        try {
            $this->postEntry('2027-01-31');

            $this->fail('an entry dated inside the frozen period was posted');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('frozen before 2027-02-01', $e->getMessage());
            $this->assertStringContainsString('2027-01-31', $e->getMessage());
        }
    }

    /** The frozen date itself is open: "before" means before. */
    public function test_the_frozen_date_itself_is_open(): void
    {
        $this->freezeBefore('2027-02-01');

        $this->assertTrue($this->postEntry('2027-02-01')->is_posted);
        $this->assertTrue($this->postEntry('2027-02-18')->is_posted);
    }

    /**
     * A draft in the frozen period is still allowed, and only posting is refused.
     *
     * The same position the closed-year guard takes, and for the same reason: somebody preparing a
     * correction should be able to write it down, and what is protected is the ledger rather than the
     * drafting.
     */
    public function test_a_draft_can_still_be_written_inside_the_frozen_period(): void
    {
        $this->freezeBefore('2027-02-01');

        $draft = $this->draft('2027-01-15');

        $this->assertSame(JournalEntry::STATUS_DRAFT, $draft->status);
        $this->assertFalse((bool) $draft->is_posted);
    }

    /**
     * And a holder of the exemption may post anyway.
     *
     * Written as a permission grant rather than as "an Administrator", because that is the claim: the
     * exemption is a named permission somebody can be given without being given everything.
     */
    public function test_a_holder_of_the_exemption_may_post_into_the_frozen_period(): void
    {
        $this->freezeBefore('2027-02-01');

        $role = Role::create([
            'name' => 'Closing accountant',
            'guard_name' => 'web',
            'company_id' => $this->tenant->getKey(),
        ]);
        $role->givePermissionTo(['JournalEntryBackdate', 'JournalEntryPost', 'JournalEntryCreate']);

        $user = $this->makeUser('Accountant', 'closer@test.local');
        $user->syncRoles([$role]);
        $this->actingAs($user);

        $this->assertTrue($this->postEntry('2027-01-20')->is_posted);
    }

    /** A closed year is still refused, exemption or not — the two guards are separate rules. */
    public function test_the_frozen_date_does_not_replace_the_closed_year_guard(): void
    {
        $this->freezeBefore('2020-01-01');

        $this->fiscalYear->update(['closed_at' => now()]);

        try {
            $this->postEntry('2027-02-10');

            $this->fail('an entry was posted into a closed fiscal year');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('is closed', $e->getMessage());
        }
    }

    // ───────────────────────────────────────────────────────── fixtures ──

    private function freezeBefore(string $date): void
    {
        app(TenantSettings::class)->set('accounting.ledger_frozen_before', $date);
    }

    private function draft(string $date): JournalEntry
    {
        return app(JournalEntryService::class)->create([
            'entry_date' => $date,
            'entry_type' => 'general',
            'memo' => 'Rent for the month',
        ], [
            ['account_id' => Account::where('code', '5100')->firstOrFail()->id, 'debit_amount' => 1_000],
            ['account_id' => Account::where('code', '1100')->firstOrFail()->id, 'credit_amount' => 1_000],
        ]);
    }

    private function postEntry(string $date): JournalEntry
    {
        $entries = app(JournalEntryService::class);
        $entry = $this->draft($date);

        $entry->update(['status' => JournalEntry::STATUS_APPROVED, 'approved_at' => now()]);

        return $entries->post($entry);
    }
}
