<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Core\Models\FiscalYear;
use App\Support\TenantSettings;

/**
 * The company's return, assembled: the ledger's profit, the tax adjustments a
 * ledger cannot know, and the figures IRIS nets against each other.
 *
 * The same posture as the personal pack: FBR exposes no filing API, so this
 * prepares and never transmits. What makes the corporate one different is that
 * **accounting profit is not taxable income** — tax depreciation, inadmissible
 * expenses and their kin live in the Ordinance, not in the books — so between the
 * P&L and the tax sits a small hand-kept worksheet: adjustment rows (signed:
 * positive adds back, negative deducts) and the two rates a Finance Act moves.
 * The worksheet is stored per fiscal year in TenantSettings, the same home as
 * every other per-company figure somebody types once; move it to tables of its
 * own the day multiple preparers need an audit trail over it.
 *
 * Two computations IRIS runs and this pack therefore shows side by side:
 * normal tax on taxable income, and section 113 minimum tax on turnover — the
 * greater applies. Super tax (4C) is deliberately absent: its slabs move yearly
 * and start at incomes this pack's one screen would misstate more often than
 * help; the PDF says so instead of guessing.
 */
class CorporateReturnPack
{
    /** Corporate rate, editable per year on the worksheet — Finance Act 2025 value. */
    public const DEFAULT_TAX_RATE = 29.0;

    /** Section 113 minimum tax on turnover, editable the same way. */
    public const DEFAULT_MINIMUM_TAX_RATE = 1.25;

    /** The chart code ChartOfAccountsSeeder gives the company's own advance/withheld tax. */
    public const TAX_PAID_ACCOUNT_CODE = '1260';

    public function __construct(private FinancialReportService $statements) {}

    /**
     * @return array{tax_rate: float, minimum_tax_rate: float, adjustments: array<int, array{label: string, amount: float}>}
     */
    public function worksheet(int $fiscalYearId): array
    {
        return $this->normalized((array) setting("corporate_return.{$fiscalYearId}", []));
    }

    /**
     * Persist the worksheet. Scalars and a list of {label, amount} rows only —
     * whatever else a form sends is not stored.
     *
     * @param  array<string, mixed>  $worksheet
     */
    public function saveWorksheet(int $fiscalYearId, array $worksheet): void
    {
        app(TenantSettings::class)->set("corporate_return.{$fiscalYearId}", $this->normalized($worksheet));
    }

    /**
     * One shape wherever a worksheet comes from — the settings row, a form's
     * live state, a test's literal.
     *
     * @param  array<string, mixed>  $worksheet
     * @return array{tax_rate: float, minimum_tax_rate: float, adjustments: array<int, array{label: string, amount: float}>}
     */
    private function normalized(array $worksheet): array
    {
        return [
            'tax_rate' => (float) ($worksheet['tax_rate'] ?? self::DEFAULT_TAX_RATE),
            'minimum_tax_rate' => (float) ($worksheet['minimum_tax_rate'] ?? self::DEFAULT_MINIMUM_TAX_RATE),
            // Brought-forward business loss set against this year's taxable income.
            // A prior-year figure the ledger cannot know, so it is entered here;
            // floored at zero because a negative "loss" would be income by the back door.
            'brought_forward_loss' => round(max(0, (float) ($worksheet['brought_forward_loss'] ?? 0)), 2),
            // Super tax (s.4C) as an AMOUNT, not a rate: its slabs move every Finance
            // Act and start above most companies' income, so the practitioner computes
            // it and enters the figure rather than the pack guessing a bracket table.
            'super_tax' => round(max(0, (float) ($worksheet['super_tax'] ?? 0)), 2),
            'adjustments' => array_values(array_filter(
                array_map(fn (array $row): array => [
                    'label' => trim((string) ($row['label'] ?? '')),
                    'amount' => (float) ($row['amount'] ?? 0),
                ], array_filter((array) ($worksheet['adjustments'] ?? []), 'is_array')),
                // A row naming nothing and adjusting nothing is a click that
                // happened, not an adjustment.
                fn (array $row): bool => $row['label'] !== '' || abs($row['amount']) >= 0.005,
            )),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function build(int $fiscalYearId, ?array $worksheet = null): array
    {
        $year = FiscalYear::findOrFail($fiscalYearId);
        $pnl = $this->statements->profitAndLoss(fiscalYearId: $fiscalYearId);

        // A worksheet handed in is the page's live form state — what the reader
        // is looking at computes, saved or not. Absent, the stored one applies.
        $worksheet = $worksheet !== null ? $this->normalized($worksheet) : $this->worksheet($fiscalYearId);

        $adjustmentsTotal = round(array_sum(array_column($worksheet['adjustments'], 'amount')), 2);
        $incomeAfterAdjustments = round($pnl['net_profit'] + $adjustmentsTotal, 2);

        // Brought-forward loss set against this year's income — never more than the
        // income itself (a loss cannot be created, only absorbed), and never taking
        // taxable income below zero.
        $lossApplied = round(min(max(0, $incomeAfterAdjustments), $worksheet['brought_forward_loss']), 2);
        $taxableIncome = round(max(0, $incomeAfterAdjustments - $lossApplied), 2);

        // Turnover for s.113 is the year's revenue; a negative income total is a
        // bookkeeping artefact, not negative turnover.
        $turnover = round(max(0, $pnl['income']['total']), 2);

        $normalTax = round($taxableIncome * $worksheet['tax_rate'] / 100, 2);
        $minimumTax = round($turnover * $worksheet['minimum_tax_rate'] / 100, 2);

        // Super tax (s.4C) is a separate charge ON TOP of whichever base applies,
        // not an alternative to it — so it is added after the greater-of comparison.
        $superTax = $worksheet['super_tax'];
        $taxDue = round(max($normalTax, $minimumTax) + $superTax, 2);
        $taxPaid = $this->taxPaidIn($fiscalYearId);

        return [
            'year' => $year,
            'pnl' => $pnl,
            'worksheet' => $worksheet,
            'adjustments_total' => $adjustmentsTotal,
            'income_after_adjustments' => $incomeAfterAdjustments,
            'brought_forward_loss' => $worksheet['brought_forward_loss'],
            'loss_applied' => $lossApplied,
            'taxable_income' => $taxableIncome,
            'turnover' => $turnover,
            'normal_tax' => $normalTax,
            'minimum_tax' => $minimumTax,
            'super_tax' => $superTax,
            // Which computation IRIS will apply — named, because "the bigger
            // number won" is the sentence an accountant checks first. Super tax
            // rides on top of whichever this is.
            'basis' => $minimumTax > $normalTax ? 'minimum' : 'normal',
            'tax_due' => $taxDue,
            'tax_paid' => $taxPaid,
            // Positive: pay with the return. Negative: claim or carry the refund.
            'balance' => round($taxDue - $taxPaid, 2),
        ];
    }

    /**
     * Tax the company already suffered: the in-year movement of 1260 (Advance
     * Income Tax — what customers withheld from receipts, plus any advance tax
     * booked there). An account somebody deleted reports zero, which understates
     * nothing the books know about.
     */
    private function taxPaidIn(int $fiscalYearId): float
    {
        $account = Account::query()->where('code', self::TAX_PAID_ACCOUNT_CODE)->first();

        if (! $account) {
            return 0.0;
        }

        $lines = JournalEntryLine::query()
            ->where('account_id', $account->id)
            ->whereHas('journalEntry', fn ($query) => $query
                ->where('is_posted', true)
                ->where('fiscal_year_id', $fiscalYearId));

        return round((float) (clone $lines)->sum('debit_amount') - (float) $lines->sum('credit_amount'), 2);
    }
}
