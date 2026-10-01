<?php

namespace App\Modules\PersonalFinance\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Accounting\Services\FinancialReportService;
use App\Modules\Core\Models\FiscalYear;
use App\Support\TaxRegimes;
use App\Support\TenantSettings;

/**
 * The return pack for a sole proprietor: a business keeping full business books,
 * whose profit is the owner's individual income and is taxed on the individual
 * slabs — not the corporate flat rate.
 *
 * Why this lives in PersonalFinance, not beside the Corporate pack in Accounting:
 * it is an *individual* return, and the individual slab engine (`PersonalTaxService`,
 * `tax_schedules`) is here. PersonalFinance already depends on Accounting, so it
 * reads the P&L from `FinancialReportService` the Corporate pack uses — the
 * dependency runs the allowed direction, and the slab formula is not copied.
 *
 * Structurally the Corporate pack with one line changed: accounting profit →
 * tax adjustments → taxable income, then **slab tax on the individual business
 * schedule** rather than a flat rate and s.113 minimum. (s.113 minimum tax for
 * individuals and AOPs is docs/legal-entity-types-plan.md phase 3; until it
 * lands a high-turnover sole proprietor is taxed on profit alone, which the page
 * states.)
 *
 * The assessment of a sole proprietor's business income on the individual
 * schedule is the standard treatment and is §7 Q4 of the plan — confirm with the
 * advisor before a company relies on it.
 */
class SoleProprietorReturnPack
{
    /** The business chart's advance/withheld income-tax asset — tax already paid. */
    public const TAX_PAID_ACCOUNT_CODE = '1260';

    public function __construct(
        private FinancialReportService $statements,
        private PersonalTaxService $tax,
    ) {}

    /**
     * @return array{adjustments: array<int, array{label: string, amount: float}>}
     */
    public function worksheet(int $fiscalYearId): array
    {
        return $this->normalized((array) setting("sole_proprietor_return.{$fiscalYearId}", []));
    }

    /** @param  array<string, mixed>  $worksheet */
    public function saveWorksheet(int $fiscalYearId, array $worksheet): void
    {
        app(TenantSettings::class)->set("sole_proprietor_return.{$fiscalYearId}", $this->normalized($worksheet));
    }

    /**
     * @param  array<string, mixed>  $worksheet
     * @return array{adjustments: array<int, array{label: string, amount: float}>}
     */
    private function normalized(array $worksheet): array
    {
        return [
            'adjustments' => array_values(array_filter(
                array_map(fn (array $row): array => [
                    'label' => trim((string) ($row['label'] ?? '')),
                    'amount' => (float) ($row['amount'] ?? 0),
                ], array_filter((array) ($worksheet['adjustments'] ?? []), 'is_array')),
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
        $worksheet = $worksheet !== null ? $this->normalized($worksheet) : $this->worksheet($fiscalYearId);

        $adjustmentsTotal = round(array_sum(array_column($worksheet['adjustments'], 'amount')), 2);
        $taxableIncome = round(max(0, $pnl['net_profit'] + $adjustmentsTotal), 2);

        // Slab tax on the individual business schedule — the one line that makes
        // this not the corporate pack. taxFor carries the bracket and surcharge.
        $taxResult = $this->tax->taxFor($taxableIncome, TaxRegimes::BUSINESS, $fiscalYearId);
        $taxDue = round($taxResult['total'], 2);
        $taxPaid = $this->taxPaidIn($fiscalYearId);

        return [
            'year' => $year,
            'pnl' => $pnl,
            'worksheet' => $worksheet,
            'adjustments_total' => $adjustmentsTotal,
            'taxable_income' => $taxableIncome,
            'tax' => $taxResult,
            'tax_due' => $taxDue,
            'tax_paid' => $taxPaid,
            // Positive: pay with the return. Negative: refundable.
            'balance' => round($taxDue - $taxPaid, 2),
        ];
    }

    /**
     * Advance income tax already suffered — the in-year movement of 1260, net of
     * corrections. A deleted account reports zero, understating nothing the books know.
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
