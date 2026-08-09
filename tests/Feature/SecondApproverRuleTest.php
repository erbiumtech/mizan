<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\Loan;
use App\Modules\Accounting\Models\ScheduledTransaction;
use App\Modules\Accounting\Services\JournalEntryService;
use App\Modules\Accounting\Services\LoanService;
use App\Modules\Accounting\Services\ScheduledTransactionService;
use App\Modules\Accounting\Services\SecondApproverRule;
use App\Modules\Core\Models\User;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Segregation of duties, and the company that has nobody to segregate with.
 *
 * The control is right and stays on by default. What it cannot do is assume a
 * second person exists: a company run by one operator has no approver to route
 * an entry to, so the rule stops being a control and becomes a dead end — the
 * entry waits forever while the money it describes has already left the bank.
 * That is not hypothetical; it is how a month's payroll came to be paid with its
 * accrual unposted and Salaries Payable at minus the whole month.
 *
 * So the two things worth pinning are that the default has not moved, and that
 * turning it off leaves a trace.
 */
class SecondApproverRuleTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private User $author;

    protected function setUp(): void
    {
        parent::setUp();

        $this->author = $this->makeUser('Administrator', 'solo@test.local');
        $this->actingAs($this->author);
        $this->setCurrentTenant();
    }

    private function rule(): SecondApproverRule
    {
        return app(SecondApproverRule::class);
    }

    private function account(string $code): Account
    {
        return Account::where('code', $code)->firstOrFail();
    }

    private function draft(): JournalEntry
    {
        $entry = app(JournalEntryService::class)->create(
            ['entry_date' => '2026-07-10', 'entry_type' => 'general', 'memo' => 'Test'],
            [
                ['account_id' => $this->account('5700')->id, 'debit_amount' => 5000],
                ['account_id' => $this->account('1100')->id, 'credit_amount' => 5000],
            ],
        );

        $entry->update(['created_by' => $this->author->id, 'status' => JournalEntry::STATUS_PENDING]);

        return $entry->fresh();
    }

    // ── the default has not moved ───────────────────────────────────────────

    public function test_a_second_approver_is_required_by_default(): void
    {
        $this->assertTrue($this->rule()->default());
        $this->assertTrue($this->rule()->isRequired());
    }

    public function test_by_default_an_author_still_cannot_approve_their_own_entry(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/segregation of duties/');

        app(JournalEntryService::class)->approve($this->draft(), $this->author);
    }

    public function test_somebody_else_can_always_approve(): void
    {
        $other = $this->makeUser('Manager', 'other@test.local');

        $entry = app(JournalEntryService::class)->approve($this->draft(), $other);

        $this->assertTrue($entry->isApproved());
        $this->assertSame($other->id, $entry->approved_by);
    }

    // ── the company with nobody to approve ──────────────────────────────────

    public function test_turning_it_off_lets_one_person_clear_their_own_entry(): void
    {
        $this->rule()->set(false);

        $entry = app(JournalEntryService::class)->approve($this->draft(), $this->author);

        $this->assertTrue($entry->isApproved());
        $this->assertSame($this->author->id, $entry->approved_by, 'The person who actually approved it is named.');

        // And it can now reach the ledger, which is the whole point.
        $this->assertTrue(app(JournalEntryService::class)->post($entry->fresh())->is_posted);
    }

    public function test_a_self_approval_is_recorded_as_one(): void
    {
        // A waived control that leaves no trace is worse than no control: the
        // point of turning it off is to keep working, not to make the books look
        // like two people checked them.
        $this->rule()->set(false);

        $entry = app(JournalEntryService::class)->approve($this->draft(), $this->author);

        $log = \Spatie\Activitylog\Models\Activity::where('event', 'approved')
            ->latest('id')
            ->firstOrFail();

        $this->assertTrue((bool) $log->properties['self_approved']);
        $this->assertStringContainsString('by its own author', $log->description);
    }

    public function test_an_ordinary_approval_is_not_flagged(): void
    {
        $other = $this->makeUser('Manager', 'other2@test.local');

        app(JournalEntryService::class)->approve($this->draft(), $other);

        $log = \Spatie\Activitylog\Models\Activity::where('event', 'approved')->latest('id')->firstOrFail();

        $this->assertArrayNotHasKey('self_approved', $log->properties->toArray());
    }

    // ── what the app raises for itself ──────────────────────────────────────

    private function schedule(): ScheduledTransaction
    {
        $s = ScheduledTransaction::create([
            'name' => 'Office rent',
            'interval_months' => 1,
            'day_of_month' => 1,
            'starts_on' => '2026-07-01',
        ]);

        $s->lines()->createMany([
            ['account_id' => $this->account('5700')->id, 'debit_amount' => 50_000, 'sort' => 0],
            ['account_id' => $this->account('1100')->id, 'credit_amount' => 50_000, 'sort' => 1],
        ]);

        return $s->fresh('lines');
    }

    public function test_a_scheduled_entry_stays_a_draft_where_somebody_will_read_it(): void
    {
        $this->schedule();

        $raised = app(ScheduledTransactionService::class)->run(CarbonImmutable::parse('2026-07-01'));

        $this->assertCount(1, $raised);
        $this->assertSame(JournalEntry::STATUS_DRAFT, $raised->first()->status);
    }

    public function test_a_scheduled_entry_posts_itself_where_nobody_will(): void
    {
        // Otherwise the rent waits for an approver who does not exist while the
        // money has already gone.
        $this->rule()->set(false);
        $this->schedule();

        $entry = app(ScheduledTransactionService::class)->run(CarbonImmutable::parse('2026-07-01'))->first();

        $this->assertTrue($entry->is_posted);
        $this->assertNull($entry->approved_by, 'No person approved it, so no person is named.');
    }

    public function test_a_loan_instalment_follows_the_same_rule(): void
    {
        $loan = Loan::create([
            'name' => 'Vehicle finance',
            'liability_account_id' => $this->account('2100')->id,
            'interest_account_id' => $this->account('5900')->id,
            'payment_account_id' => $this->account('1100')->id,
            'principal' => 1_200_000, 'annual_rate' => 12, 'term_months' => 12,
            'starts_on' => '2026-07-05',
        ]);
        app(LoanService::class)->generateSchedule($loan);

        $this->assertSame(
            JournalEntry::STATUS_DRAFT,
            app(LoanService::class)->recordInstalment($loan->fresh()->instalments()->first())->status,
        );

        $this->rule()->set(false);

        $second = app(LoanService::class)->recordInstalment($loan->fresh()->instalments()->skip(1)->first());

        $this->assertTrue($second->is_posted);
        $this->assertNull($second->approved_by);
    }

    public function test_the_setting_is_per_company(): void
    {
        $this->rule()->set(false);

        $this->assertFalse($this->rule()->isRequired());
        $this->assertTrue($this->rule()->default(), 'The shipped default is untouched by one company opting out.');
    }

    // ── the installation default, from .env ─────────────────────────────────

    /**
     * ACCOUNTING_REQUIRE_SECOND_APPROVER reaches config/accounting.php, and a
     * company that has never chosen follows it. This is how an installation
     * that only ever serves the one-operator company is set up: edit .env once,
     * never open Company Settings.
     */
    public function test_a_company_that_has_never_chosen_follows_the_installation_default(): void
    {
        config(['accounting.require_second_approver' => false]);

        $this->assertFalse($this->rule()->default());
        $this->assertFalse($this->rule()->isRequired(), 'No override saved, so the .env value decides.');

        // And it decides for real work, not just the flag: the author clears
        // their own entry without anybody having touched the settings page.
        $entry = app(JournalEntryService::class)->approve($this->draft(), $this->author);

        $this->assertTrue($entry->isApproved());
    }

    public function test_a_company_that_has_chosen_keeps_its_choice_when_the_installation_default_changes(): void
    {
        // The company has answered the question for itself. A later deploy that
        // flips the env var must not answer it again — least of all by turning
        // a control back on for a company that has nobody to satisfy it.
        $this->rule()->set(false);

        config(['accounting.require_second_approver' => true]);

        $this->assertFalse($this->rule()->isRequired());
        $this->assertTrue(
            app(JournalEntryService::class)->approve($this->draft(), $this->author)->isApproved(),
        );
    }

    public function test_a_company_can_still_be_stricter_than_the_installation_default(): void
    {
        config(['accounting.require_second_approver' => false]);
        $this->rule()->set(true);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/segregation of duties/');

        app(JournalEntryService::class)->approve($this->draft(), $this->author);
    }
}
