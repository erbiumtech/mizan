<?php

namespace Tests\Feature;

use App\Modules\Accounting\Filament\Pages\BalanceSheet;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\FinancialReportService;
use App\Modules\Accounting\Services\FiscalYearClosingService;
use App\Modules\Accounting\Services\JournalEntryService;
use App\Modules\Core\Models\FiscalYear;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The statement of position.
 *
 * The gap Akaunting's Double-Entry app leads with, and the one report an auditor
 * asks for first: the books had a trial balance proving they add up and nothing
 * saying what they say.
 *
 * Derived from the trial balance rather than from the ledger a second time, so
 * "does it tie to the trial balance" is true by construction rather than by luck.
 * What is left to prove is the accounting: that it balances, that a contra-asset
 * reduces assets, and that earnings land in the right place either side of a
 * year-end close.
 */
class BalanceSheetTest extends AccountingTestCase
{
    use InteractsWithTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'balance@test.local'));
        $this->setCurrentTenant();
    }

    private function postEntry(string $date, array $lines, string $memo = 'Test'): JournalEntry
    {
        $entries = app(JournalEntryService::class);

        $entry = $entries->create(
            ['entry_date' => $date, 'entry_type' => 'general', 'memo' => $memo],
            collect($lines)->map(fn (array $line): array => [
                'account_id' => Account::where('code', $line[0])->firstOrFail()->id,
                $line[1] => $line[2],
            ])->all(),
        );

        $entry->update(['status' => JournalEntry::STATUS_APPROVED, 'approved_at' => now()]);

        return $entries->post($entry);
    }

    private function sheet(string $asOf = '2026-08-31'): array
    {
        return app(FinancialReportService::class)->balanceSheet($asOf);
    }

    public function test_an_empty_book_balances_at_nothing(): void
    {
        $sheet = $this->sheet();

        $this->assertTrue($sheet['balanced']);
        $this->assertSame(0.0, $sheet['assets']['total']);
        $this->assertSame(0.0, $sheet['liabilities_and_equity_total']);
    }

    public function test_assets_equal_liabilities_plus_equity(): void
    {
        // Money in from the owner, then a cost incurred but not yet paid.
        $this->postEntry('2026-07-01', [['1100', 'debit_amount', 500000], ['3100', 'credit_amount', 500000]], 'Capital');
        $this->postEntry('2026-07-05', [['5700', 'debit_amount', 92000], ['2400', 'credit_amount', 92000]], 'Rent owed');

        $sheet = $this->sheet();

        $this->assertSame(500000.0, $sheet['assets']['total'], 'cash');
        $this->assertSame(92000.0, $sheet['liabilities']['total'], 'the unpaid rent');
        $this->assertSame(-92000.0, $sheet['retained_earnings_for_period'], 'the rent is a loss so far');
        $this->assertSame(408000.0, $sheet['equity_total'], '500,000 capital less the 92,000 loss');
        $this->assertSame(500000.0, $sheet['liabilities_and_equity_total']);
        $this->assertTrue($sheet['balanced']);
    }

    /**
     * The line that is in no account. Income and expense accounts are zeroed into
     * Retained Earnings only at year-end, so between closes the profit sits in them
     * and nowhere else — leave it out and assets exceed liabilities plus equity by
     * exactly the profit.
     */
    public function test_earnings_not_yet_closed_are_shown_as_equity(): void
    {
        $this->postEntry('2026-07-10', [['1100', 'debit_amount', 300000], ['4100', 'credit_amount', 300000]], 'Fees');

        $sheet = $this->sheet();

        $this->assertSame(300000.0, $sheet['retained_earnings_for_period']);
        $this->assertSame(0.0, $sheet['equity']['total'], 'no equity account has been touched');
        $this->assertSame(300000.0, $sheet['equity_total']);
        $this->assertTrue($sheet['balanced']);
    }

    /** The same figure the Profit & Loss reports for the same date, or one of them is wrong. */
    public function test_the_earnings_line_matches_the_profit_and_loss(): void
    {
        $this->postEntry('2026-07-10', [['1100', 'debit_amount', 300000], ['4100', 'credit_amount', 300000]], 'Fees');
        $this->postEntry('2026-07-15', [['5700', 'debit_amount', 92000], ['1100', 'credit_amount', 92000]], 'Rent paid');

        $reports = app(FinancialReportService::class);

        $this->assertSame(
            round((float) $reports->profitAndLoss(null, '2026-08-31')['net_profit'], 2),
            $reports->balanceSheet('2026-08-31')['retained_earnings_for_period'],
        );
    }

    /**
     * 1500 Accumulated Depreciation is an asset with a credit normal balance, and
     * belongs in assets as a deduction. Signing rows by the account's own normal
     * side rather than by the section's would add the depreciation to what the
     * company owns — this test is what caught that being the wrong way round.
     */
    public function test_a_contra_asset_reduces_assets(): void
    {
        $this->postEntry('2026-07-01', [['1400', 'debit_amount', 200000], ['3100', 'credit_amount', 200000]], 'Equipment');
        $this->postEntry('2026-07-31', [['5990', 'debit_amount', 20000], ['1500', 'credit_amount', 20000]], 'Depreciation');

        $sheet = $this->sheet();

        $accumulated = collect($sheet['assets']['rows'])->firstWhere('code', '1500');

        $this->assertSame(-20000.0, $accumulated['amount'], 'shown as negative');
        $this->assertSame(180000.0, $sheet['assets']['total'], '200,000 less 20,000 of depreciation');
        $this->assertTrue($sheet['balanced']);
    }

    public function test_it_ties_to_the_trial_balance(): void
    {
        $this->postEntry('2026-07-01', [['1100', 'debit_amount', 500000], ['3100', 'credit_amount', 500000]]);
        $this->postEntry('2026-07-05', [['5700', 'debit_amount', 92000], ['2400', 'credit_amount', 92000]]);
        $this->postEntry('2026-07-10', [['1100', 'debit_amount', 300000], ['4100', 'credit_amount', 300000]]);

        $reports = app(FinancialReportService::class);
        $trial = $reports->trialBalance('2026-08-31');
        $sheet = $reports->balanceSheet('2026-08-31');

        $this->assertTrue($trial['balanced']);
        $this->assertTrue($sheet['balanced']);

        // Every asset row on the statement appears on the trial balance at the same
        // figure, which is what "derived from" has to mean to be worth anything.
        $trialByCode = collect($trial['sections'])->flatMap(fn (array $s): array => $s['rows'])->keyBy('code');

        foreach ($sheet['assets']['rows'] as $row) {
            $this->assertSame(
                $row['amount'],
                round($trialByCode[$row['code']]['debit'] - $trialByCode[$row['code']]['credit'], 2),
                "asset {$row['code']} disagrees with the trial balance",
            );
        }
    }

    /**
     * The risk the plan document named: closing moves the year's profit out of the
     * income and expense accounts and into Retained Earnings, so it must appear in
     * exactly one of the two lines — never both, never neither.
     */
    public function test_closing_the_year_moves_earnings_into_retained_earnings(): void
    {
        $year = FiscalYear::where('name', '2025-2026')->firstOrFail();

        $this->postEntry($year->end_date->copy()->subDay()->toDateString(), [
            ['1100', 'debit_amount', 300000], ['4100', 'credit_amount', 300000],
        ], 'Fees earned in the year being closed');

        $asOf = $year->end_date->toDateString();
        $before = $this->sheet($asOf);

        $this->assertSame(300000.0, $before['retained_earnings_for_period']);
        $this->assertSame(0.0, $before['equity']['total']);

        app(FiscalYearClosingService::class)->close($year, auth()->user());

        $after = $this->sheet($asOf);

        $this->assertSame(0.0, $after['retained_earnings_for_period'], 'no longer sitting in income');
        $this->assertSame(300000.0, $after['equity']['total'], 'now in Retained Earnings');
        $this->assertSame($before['equity_total'], $after['equity_total'], 'and equity is unchanged overall');
        $this->assertTrue($after['balanced']);
    }

    public function test_the_page_renders_and_reports_the_total(): void
    {
        $this->postEntry('2026-07-01', [['1100', 'debit_amount', 500000], ['3100', 'credit_amount', 500000]]);

        Livewire::test(BalanceSheet::class)
            ->assertSet('data.as_of', fn ($value): bool => \Illuminate\Support\Carbon::parse($value)->isToday())
            ->assertSee('500,000.00')
            ->assertSee('Balanced');
    }

    public function test_the_report_page_and_its_pdf_are_reachable(): void
    {
        $this->postEntry('2026-07-01', [['1100', 'debit_amount', 500000], ['3100', 'credit_amount', 500000]]);

        $url = route('reports.balance-sheet', ['company' => $this->tenant->slug, 'as_of' => '2026-08-31']);

        $this->get($url)->assertOk()->assertSee('Balance Sheet')->assertSee('500,000.00');
        $this->get($url.'&format=pdf')->assertOk()->assertHeader('content-type', 'application/pdf');
    }
}
