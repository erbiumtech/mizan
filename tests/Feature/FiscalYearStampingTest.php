<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\JournalEntryService;
use App\Modules\Core\Models\FiscalYear;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Every journal entry knowing which fiscal year it belongs to, and exactly one
 * year calling itself active.
 *
 * Neither held. Payroll passed a fiscal year when it created an entry and nothing
 * else did, so two thirds of the ledger carried none — invisible while nothing
 * filtered on it, and silently missing from the report the day something did. And
 * two years could be active at once, which no caller treats as an error because
 * they all ask `where is_active, first()` and take what they get.
 */
class FiscalYearStampingTest extends AccountingTestCase
{
    use InteractsWithTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'fiscal@test.local'));
        $this->setCurrentTenant();
    }

    private function entry(string $date, array $header = []): JournalEntry
    {
        return app(JournalEntryService::class)->create(
            array_merge(['entry_date' => $date, 'entry_type' => 'general', 'memo' => 'Test'], $header),
            [
                ['account_id' => Account::where('code', '5700')->firstOrFail()->id, 'debit_amount' => 1000],
                ['account_id' => Account::where('code', '1100')->firstOrFail()->id, 'credit_amount' => 1000],
            ],
        );
    }

    public function test_an_entry_is_filed_under_the_year_its_date_falls_in(): void
    {
        $year = FiscalYear::containing('2026-08-02');

        $this->assertNotNull($year);
        $this->assertSame($year->getKey(), $this->entry('2026-08-02')->fiscal_year_id);
    }

    public function test_a_year_the_caller_states_is_kept(): void
    {
        // Payroll knows which year a payslip belongs to even when the entry is
        // dated outside it, and its answer outranks the date.
        $other = FiscalYear::where('name', '2025-2026')->firstOrFail();

        $entry = $this->entry('2026-08-02', ['fiscal_year_id' => $other->getKey()]);

        $this->assertSame($other->getKey(), $entry->fiscal_year_id);
    }

    public function test_a_date_outside_every_year_is_left_unfiled(): void
    {
        // Better no year than the wrong one.
        $this->assertNull($this->entry('2019-01-01')->fiscal_year_id);
    }

    /**
     * A reversal is posted today. Filing it under the year of what it reverses
     * would put its date and its year in disagreement.
     */
    public function test_a_reversal_belongs_to_the_year_it_is_dated_in(): void
    {
        $old = FiscalYear::where('name', '2025-2026')->firstOrFail();
        $entries = app(JournalEntryService::class);

        $entry = $this->entry('2026-06-30');
        $this->assertSame($old->getKey(), $entry->fiscal_year_id);

        $entry->update(['status' => JournalEntry::STATUS_APPROVED, 'approved_at' => now()]);
        $entries->post($entry);

        $reversal = $entries->reverse($entry->refresh());

        $this->assertSame(
            FiscalYear::containing(now()->toDateString())?->getKey(),
            $reversal->fiscal_year_id,
            'the year it is dated in',
        );
    }

    /**
     * Also the case where the second model was loaded while it was active and
     * stood down since: it is not dirty when activated again, so Eloquent writes
     * nothing and the row would stay false while every other year is stood down —
     * leaving no active year at all.
     */
    public function test_activating_a_year_stands_the_others_down(): void
    {
        $first = FiscalYear::where('name', '2025-2026')->firstOrFail();
        $second = FiscalYear::where('name', '2026-2027')->firstOrFail();

        $first->update(['is_active' => true]);
        $second->update(['is_active' => true]);

        $this->assertSame(1, FiscalYear::where('is_active', true)->count());
        $this->assertTrue($second->fresh()->is_active);
        $this->assertFalse($first->fresh()->is_active);
    }

    public function test_deactivating_a_year_leaves_the_others_alone(): void
    {
        $first = FiscalYear::where('name', '2025-2026')->firstOrFail();
        $second = FiscalYear::where('name', '2026-2027')->firstOrFail();

        $second->update(['is_active' => true]);
        $second->update(['is_active' => false]);

        $this->assertSame(0, FiscalYear::where('is_active', true)->count());
        $this->assertFalse($first->fresh()->is_active);
    }

    public function test_current_returns_the_active_year(): void
    {
        $year = FiscalYear::where('name', '2026-2027')->firstOrFail();
        $year->update(['is_active' => true]);

        $this->assertSame($year->getKey(), FiscalYear::current()?->getKey());
    }
}
