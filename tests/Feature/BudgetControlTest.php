<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Budget;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\BudgetControl;
use App\Modules\Accounting\Services\JournalEntryService;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * A budget that argues back, and only argues — `docs/erpnext-gap-plan.md` §4 item 8.
 *
 * Every test here asks the same question a bill's Issue button asks: "if this were booked today, what
 * would be over?" The answer is sentences or nothing; nothing here can refuse anything.
 */
class BudgetControlTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private BudgetControl $control;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'budget@test.local'));
        $this->setCurrentTenant();

        $this->control = app(BudgetControl::class);
    }

    private function account(string $code): Account
    {
        return Account::where('code', $code)->firstOrFail();
    }

    private function plan(string $code, array $monthly): Budget
    {
        $budget = Budget::create(['fiscal_year_id' => $this->fiscalYear->getKey(), 'name' => 'Plan']);

        foreach ($monthly as $monthStart => $amount) {
            $budget->lines()->create(['account_id' => $this->account($code)->id, 'period_start' => $monthStart, 'amount' => $amount]);
        }

        return $budget;
    }

    private function spend(string $code, float $amount, string $date): void
    {
        $entries = app(JournalEntryService::class);
        $entry = $entries->create(
            ['entry_date' => $date, 'entry_type' => 'general', 'memo' => 'Spend'],
            [
                ['account_id' => $this->account($code)->id, 'debit_amount' => $amount],
                ['account_id' => $this->account('1100')->id, 'credit_amount' => $amount],
            ],
        );
        $entry->update(['status' => JournalEntry::STATUS_APPROVED, 'approved_at' => now()]);
        $entries->post($entry);
    }

    public function test_no_budget_means_no_opinion(): void
    {
        $this->assertSame([], $this->control->warningsFor([[$this->account('5900')->id, 50_000]], '2026-09-15'));
    }

    public function test_a_bill_that_would_exceed_the_month_is_named_with_both_figures(): void
    {
        $this->plan('5900', ['2026-09-01' => 10_000, '2026-10-01' => 10_000]);
        $this->spend('5900', 8_000, '2026-09-03');

        $warnings = $this->control->warningsFor([[$this->account('5900')->id, 5_000]], '2026-09-15');

        $this->assertCount(1, $warnings, 'over for the month, not yet for the year');
        $this->assertStringContainsString('8,000 spent in September plus this 5,000 is 13,000', $warnings[0]);
        $this->assertStringContainsString('budget of 10,000 for the month', $warnings[0]);
    }

    public function test_the_year_is_checked_even_when_the_month_is_fine(): void
    {
        $this->plan('5900', ['2026-07-01' => 10_000, '2026-08-01' => 10_000, '2026-09-01' => 10_000]);
        // July overspent, September under: the month looks fine and the year is nearly gone.
        $this->spend('5900', 12_000, '2026-07-03');
        $this->spend('5900', 10_000, '2026-08-03');
        $this->spend('5900', 5_000, '2026-09-03');

        // 3,000 more: September at 8,000 of 10,000, the year at exactly 30,000 of 30,000. Nothing to say.
        $this->assertSame([], $this->control->warningsFor([[$this->account('5900')->id, 3_000]], '2026-09-15'));

        // 4,000 more: September still inside its month, the year over by a thousand — one warning, the year's.
        $warnings = $this->control->warningsFor([[$this->account('5900')->id, 4_000]], '2026-09-15');

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('27,000 spent this year plus this 4,000 is 31,000, against 30,000 for the whole of', $warnings[0]);
    }

    public function test_within_budget_says_nothing_and_an_unbudgeted_account_is_not_a_warning(): void
    {
        $this->plan('5900', ['2026-09-01' => 10_000]);

        $this->assertSame([], $this->control->warningsFor([[$this->account('5900')->id, 9_999]], '2026-09-15'));
        // A different expense account with no plan at all: unplanned is BudgetVsActual's word, not this one's.
        $other = Account::where('type', 'expense')->where('code', '!=', '5900')->firstOrFail();
        $this->assertSame([], $this->control->warningsFor([[$other->id, 1_000_000]], '2026-09-15'));
    }
}
