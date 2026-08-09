<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Budget;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\BudgetReportService;
use App\Modules\Accounting\Services\BudgetService;
use App\Modules\Accounting\Services\FinancialReportService;
use App\Modules\Accounting\Services\JournalEntryService;
use App\Modules\Core\Models\FiscalYear;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Planning, and measuring the ledger against the plan.
 *
 * Two things here are easy to get wrong in ways nobody notices, so both are
 * pinned rather than described:
 *
 *  - A PART MONTH must count as a part month. Comparing eleven months and a week
 *    of spending against a full year of budget is the standard way an overspend
 *    is made to look fine, and it fails silently in the direction that flatters.
 *
 *  - A SAVE THAT CHANGES NOTHING must change nothing. The yearly figure on the
 *    form is spread across the months, so the naive implementation re-spreads on
 *    every save and wipes out every month anybody adjusted by hand.
 */
class BudgetTest extends AccountingTestCase
{
    use InteractsWithTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'budget@test.local'));
        $this->setCurrentTenant();
    }

    private function budget(?FiscalYear $year = null, string $name = 'Plan'): Budget
    {
        return Budget::create([
            'fiscal_year_id' => ($year ?? $this->fiscalYear)->getKey(),
            'name' => $name,
        ]);
    }

    private function account(string $code): Account
    {
        return Account::where('code', $code)->firstOrFail();
    }

    /** @param array<int, array{0: string, 1: string, 2: float}> $lines */
    private function postEntry(string $date, array $lines): JournalEntry
    {
        $entries = app(JournalEntryService::class);

        $entry = $entries->create(
            ['entry_date' => $date, 'entry_type' => 'general', 'memo' => 'Test'],
            collect($lines)->map(fn (array $line): array => [
                'account_id' => $this->account($line[0])->id,
                $line[1] => $line[2],
            ])->all(),
        );

        $entry->update(['status' => JournalEntry::STATUS_APPROVED, 'approved_at' => now()]);

        return $entries->post($entry);
    }

    private function report(Budget $budget, ?string $from = null, ?string $to = null): array
    {
        return app(BudgetReportService::class)->report($budget, $from, $to);
    }

    /** @return array<string, mixed>|null */
    private function row(array $report, string $type, string $code): ?array
    {
        $section = collect($report['sections'])->firstWhere('type', $type);

        return collect($section['rows'])->firstWhere('code', $code);
    }

    // ── the spread ──────────────────────────────────────────────────────────

    public function test_a_yearly_figure_becomes_one_row_per_month(): void
    {
        $budget = $this->budget();

        app(BudgetService::class)->setAnnual($budget, $this->account('5700')->id, 120_000);

        $lines = $budget->lines()->orderBy('period_start')->get();

        $this->assertCount(12, $lines, 'A July-to-June year has twelve months.');
        $this->assertSame('2026-07-01', $lines->first()->period_start->toDateString());
        $this->assertSame('2027-06-01', $lines->last()->period_start->toDateString());
        $this->assertEqualsWithDelta(10_000, (float) $lines->first()->amount, 0.001);
    }

    public function test_the_months_add_back_to_exactly_what_was_typed(): void
    {
        $budget = $this->budget();

        // 100,000 / 12 is 8,333.333…, which rounds to a twelfth that sums to
        // 99,999.96. Four paisa is nothing and a budget that disagrees with
        // itself is the reason somebody stops believing the report.
        app(BudgetService::class)->setAnnual($budget, $this->account('5700')->id, 100_000);

        $this->assertSame(100_000.0, $budget->annualFor($this->account('5700')->id));
    }

    public function test_a_short_fiscal_year_gets_the_months_it_actually_has(): void
    {
        // A company's first year on the system is routinely a stub. Inventing
        // twelve months would compare four of them against no actuals at all and
        // report the whole thing as underspent.
        $stub = FiscalYear::create([
            'name' => 'Stub',
            'start_date' => '2026-02-01',
            'end_date' => '2026-06-30',
            'is_active' => false,
        ]);

        $budget = $this->budget($stub, 'Stub plan');

        app(BudgetService::class)->setAnnual($budget, $this->account('5700')->id, 50_000);

        $this->assertCount(5, $budget->lines()->get());
        $this->assertSame(50_000.0, $budget->annualFor($this->account('5700')->id));
    }

    public function test_adjusting_one_month_moves_the_year_by_the_same_amount(): void
    {
        $budget = $this->budget();
        $rent = $this->account('5700')->id;

        app(BudgetService::class)->setAnnual($budget, $rent, 120_000);

        $december = $budget->lines()->where('period_start', '2026-12-01')->firstOrFail();
        $december->update(['amount' => 25_000]);

        $this->assertSame(135_000.0, $budget->fresh()->annualFor($rent));
    }

    public function test_saving_an_unchanged_figure_leaves_hand_adjusted_months_alone(): void
    {
        $budget = $this->budget();
        $rent = $this->account('5700')->id;
        $service = app(BudgetService::class);

        $service->setAnnual($budget, $rent, 120_000);

        // School fees in three months, not an even twelfth: zero everything and
        // put the year into three months.
        $budget->lines()->where('account_id', $rent)->update(['amount' => 0]);
        $budget->lines()->where('account_id', $rent)
            ->whereIn('period_start', ['2026-09-01', '2027-01-01', '2027-04-01'])
            ->update(['amount' => 40_000]);

        // Somebody opens the budget and saves it having changed only the name.
        // The form still submits 120,000, because that is still the total.
        $service->syncAnnualPlan($budget->fresh(), [$rent => 120_000]);

        $september = $budget->lines()->where('period_start', '2026-09-01')->firstOrFail();
        $october = $budget->lines()->where('period_start', '2026-10-01')->firstOrFail();

        $this->assertEqualsWithDelta(40_000, (float) $september->amount, 0.001, 'A save that changed nothing re-spread the year and destroyed the adjustment.');
        $this->assertEqualsWithDelta(0, (float) $october->amount, 0.001);
    }

    public function test_changing_the_yearly_figure_does_re_spread(): void
    {
        $budget = $this->budget();
        $rent = $this->account('5700')->id;
        $service = app(BudgetService::class);

        $service->setAnnual($budget, $rent, 120_000);
        $service->setAnnual($budget, $rent, 240_000);

        $this->assertSame(240_000.0, $budget->fresh()->annualFor($rent));
        $this->assertEqualsWithDelta(
            20_000,
            (float) $budget->lines()->where('period_start', '2026-07-01')->firstOrFail()->amount,
            0.001,
        );
    }

    public function test_an_account_dropped_from_the_plan_is_removed(): void
    {
        $budget = $this->budget();
        $service = app(BudgetService::class);

        $service->syncAnnualPlan($budget, [
            $this->account('5700')->id => 120_000,
            $this->account('5750')->id => 60_000,
        ]);

        $service->syncAnnualPlan($budget->fresh(), [$this->account('5700')->id => 120_000]);

        $this->assertSame(0.0, $budget->fresh()->annualFor($this->account('5750')->id));
        $this->assertSame(12, $budget->fresh()->lines()->count());
    }

    // ── the report ──────────────────────────────────────────────────────────

    public function test_a_part_month_is_measured_against_a_part_month(): void
    {
        $budget = $this->budget();
        app(BudgetService::class)->setAnnual($budget, $this->account('5700')->id, 120_000);

        // 1 to 15 July inclusive is fifteen of July's thirty-one days.
        $report = $this->report($budget, '2026-07-01', '2026-07-15');
        $row = $this->row($report, 'expense', '5700');

        $this->assertEqualsWithDelta(10_000 * 15 / 31, $row['planned'], 0.01, implode("\n", [
            'The plan for a part month was not pro-rated.',
            'Counting the whole month makes an overspend read as an underspend,',
            'silently, until the month ends.',
        ]));

        // And the untrimmed year is still shown beside it.
        $this->assertEqualsWithDelta(120_000, $row['full_year'], 0.01);
    }

    public function test_a_whole_month_is_the_whole_month(): void
    {
        $budget = $this->budget();
        app(BudgetService::class)->setAnnual($budget, $this->account('5700')->id, 120_000);

        // Inclusive at both ends: 1 to 31 July is one month, not 30/31 of one.
        $row = $this->row($this->report($budget, '2026-07-01', '2026-07-31'), 'expense', '5700');

        $this->assertEqualsWithDelta(10_000, $row['planned'], 0.01);
    }

    public function test_actual_spending_is_measured_against_the_plan(): void
    {
        $budget = $this->budget();
        app(BudgetService::class)->setAnnual($budget, $this->account('5700')->id, 120_000);

        $this->postEntry('2026-07-10', [['5700', 'debit_amount', 8_000], ['1100', 'credit_amount', 8_000]]);

        $row = $this->row($this->report($budget, '2026-07-01', '2026-07-31'), 'expense', '5700');

        $this->assertEqualsWithDelta(10_000, $row['planned'], 0.01);
        $this->assertEqualsWithDelta(8_000, $row['actual'], 0.01);
        $this->assertEqualsWithDelta(2_000, $row['variance'], 0.01, 'Under budget on an expense is favourable, so positive.');
    }

    public function test_overspending_reads_as_a_negative_variance(): void
    {
        $budget = $this->budget();
        app(BudgetService::class)->setAnnual($budget, $this->account('5700')->id, 120_000);

        $this->postEntry('2026-07-10', [['5700', 'debit_amount', 14_000], ['1100', 'credit_amount', 14_000]]);

        $row = $this->row($this->report($budget, '2026-07-01', '2026-07-31'), 'expense', '5700');

        $this->assertEqualsWithDelta(-4_000, $row['variance'], 0.01);
    }

    public function test_income_variance_is_signed_the_other_way_round(): void
    {
        // The trap: variance as one subtraction means a column that reads "ahead"
        // on one half of the report and "behind" on the other.
        $budget = $this->budget();
        app(BudgetService::class)->setAnnual($budget, $this->account('4100')->id, 1_200_000);

        $this->postEntry('2026-07-10', [['1100', 'debit_amount', 150_000], ['4100', 'credit_amount', 150_000]]);

        $row = $this->row($this->report($budget, '2026-07-01', '2026-07-31'), 'income', '4100');

        $this->assertEqualsWithDelta(100_000, $row['planned'], 0.01);
        $this->assertEqualsWithDelta(150_000, $row['actual'], 0.01);
        $this->assertEqualsWithDelta(
            50_000,
            $row['variance'],
            0.01,
            'Earning more than planned is good news and must be positive, like spending less.',
        );
    }

    public function test_an_unbudgeted_account_still_appears(): void
    {
        // The most useful row on a budget review is the cost nobody planned for.
        // Listing only what was planned hides it by construction.
        $budget = $this->budget();
        app(BudgetService::class)->setAnnual($budget, $this->account('5700')->id, 120_000);

        $this->postEntry('2026-07-10', [['5850', 'debit_amount', 3_000], ['1100', 'credit_amount', 3_000]]);

        $row = $this->row($this->report($budget, '2026-07-01', '2026-07-31'), 'expense', '5850');

        $this->assertNotNull($row, 'An expense with no plan vanished from the report.');
        $this->assertTrue($row['unplanned']);
        $this->assertEqualsWithDelta(3_000, $row['actual'], 0.01);
        $this->assertEqualsWithDelta(-3_000, $row['variance'], 0.01);
        $this->assertNull($row['used_percent'], '0% of no budget is not a fact worth printing.');
    }

    public function test_only_posted_entries_count(): void
    {
        $budget = $this->budget();
        app(BudgetService::class)->setAnnual($budget, $this->account('5700')->id, 120_000);

        // A draft, left where it is.
        app(JournalEntryService::class)->create(
            ['entry_date' => '2026-07-10', 'entry_type' => 'general', 'memo' => 'Draft'],
            [
                ['account_id' => $this->account('5700')->id, 'debit_amount' => 99_000],
                ['account_id' => $this->account('1100')->id, 'credit_amount' => 99_000],
            ],
        );

        $row = $this->row($this->report($budget, '2026-07-01', '2026-07-31'), 'expense', '5700');

        $this->assertEqualsWithDelta(0, $row['actual'], 0.01);
    }

    public function test_the_actuals_agree_with_the_profit_and_loss(): void
    {
        // The property that makes the report trustworthy: two screens over the
        // same dates cannot report different spending.
        $budget = $this->budget();
        app(BudgetService::class)->setAnnual($budget, $this->account('5700')->id, 120_000);

        $this->postEntry('2026-07-10', [['5700', 'debit_amount', 8_000], ['1100', 'credit_amount', 8_000]]);
        $this->postEntry('2026-08-04', [['5700', 'debit_amount', 5_500], ['1100', 'credit_amount', 5_500]]);
        $this->postEntry('2026-08-20', [['5700', 'credit_amount', 500], ['1100', 'debit_amount', 500]]);

        $budgetActual = $this->row($this->report($budget, '2026-07-01', '2026-08-31'), 'expense', '5700')['actual'];

        $pnl = app(FinancialReportService::class)->profitAndLoss('2026-07-01', '2026-08-31');
        $pnlActual = collect($pnl['expenses']['rows'])->firstWhere('code', '5700')['amount'];

        $this->assertEqualsWithDelta($pnlActual, $budgetActual, 0.01);
        $this->assertEqualsWithDelta(13_000, $budgetActual, 0.01);
    }

    public function test_the_window_never_runs_past_the_year_the_budget_plans(): void
    {
        // Actuals from outside the planned year have no plan behind them, so they
        // would land on the report as pure overspend.
        $budget = $this->budget();
        app(BudgetService::class)->setAnnual($budget, $this->account('5700')->id, 120_000);

        $this->postEntry('2027-08-10', [['5700', 'debit_amount', 50_000], ['1100', 'credit_amount', 50_000]]);

        $report = $this->report($budget, '2026-07-01', '2027-12-31');

        $this->assertSame('2027-06-30', $report['to']);
        $this->assertEqualsWithDelta(0, $this->row($report, 'expense', '5700')['actual'], 0.01);
    }

    public function test_the_monthly_series_covers_the_whole_year(): void
    {
        $budget = $this->budget();
        app(BudgetService::class)->setAnnual($budget, $this->account('5700')->id, 120_000);

        $this->postEntry('2026-07-10', [['5700', 'debit_amount', 8_000], ['1100', 'credit_amount', 8_000]]);

        $monthly = $this->report($budget, '2026-07-01', '2026-07-31')['monthly'];

        $this->assertCount(12, $monthly);
        $this->assertSame('Jul 2026', $monthly[0]['label']);
        $this->assertEqualsWithDelta(-10_000, $monthly[0]['planned'], 0.01, 'Net of income less expenses, so a planned cost is negative.');
        $this->assertEqualsWithDelta(-8_000, $monthly[0]['actual'], 0.01);

        // Months past the reporting date are shown as still to come, not as zero.
        $this->assertNull($monthly[11]['actual']);
        $this->assertEqualsWithDelta(-10_000, $monthly[11]['planned'], 0.01);
    }

    public function test_an_empty_budget_says_so(): void
    {
        // A budget with no lines and a budget met to the rupee both total zero.
        $this->assertFalse($this->report($this->budget())['has_plan']);
    }
}
