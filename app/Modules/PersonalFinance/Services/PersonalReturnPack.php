<?php

namespace App\Modules\PersonalFinance\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Core\Models\FiscalYear;
use App\Support\TaxRegimes;

/**
 * Everything a person types into their IRIS return, assembled from their own books —
 * in the return's own shape, with the return's own codes.
 *
 * Modelled line by line on a real 114(1) print: every income head carries the
 * four-column split IRIS uses (Total | Subject to Final Tax | Subject to Exemption |
 * Subject to Normal Tax), every row its IRIS code, and the Computations block runs
 * 9000 → 9203 in the order the portal shows them. The load-bearing distinction is
 * final vs normal (TaxRegimes::FINAL): final-regime income — export of services
 * under s.154A, the flat capital-gains charge — never joins Taxable Income (9100);
 * its tax is the Fixed/Final block (920100) beside the slab tax on what remains.
 *
 * FBR exposes no filing API, so this prepares and never transmits: the last step is
 * a person on iris.fbr.gov.pk with these codes beside them, or a practitioner
 * handed the PDF. Exemption is a column of zeros until an exempt regime exists —
 * printed anyway, so the pack's shape is the return's shape.
 */
class PersonalReturnPack
{
    /** The chart code the seeder gives the general withheld/advance tax asset. */
    public const TAX_PAID_ACCOUNT_CODE = '1600';

    /**
     * The withholding accounts and the IRIS section each declares under — 1600 the
     * general one, 1601-1604 the sections a Pakistani individual most often meets.
     * All are summed for the total creditable tax (9201); a filer who wants IRIS's
     * per-section breakdown posts to the section account, and one who does not keeps
     * using 1600 and sees a single line.
     *
     * @var array<string, string>
     */
    private const WITHHOLDING_SECTIONS = [
        '1600' => 'General / unspecified',
        '1601' => 'Salary (s.149)',
        '1602' => 'Profit on debt (s.151)',
        '1603' => 'Property, sale/purchase (s.236C / 236K)',
        '1604' => 'Cash withdrawal & remittance (s.231AB / 236Y)',
    ];

    /**
     * Which IRIS head each regime's income declares under, and the head's label —
     * the codes a 114(1) prints. Business and export share head 3000: export of
     * services is business income whose tax happens to be final, which is exactly
     * how the return prints it (3000 total, final column carrying the export part).
     *
     * @var array<string, array{code: string, label: string}>
     */
    private const HEADS = [
        TaxRegimes::SALARIED => ['code' => '1000', 'label' => 'Income from Salary'],
        TaxRegimes::RENTAL => ['code' => '2000', 'label' => 'Income / (Loss) from Property'],
        TaxRegimes::BUSINESS => ['code' => '3000', 'label' => 'Income / (Loss) from Business'],
        TaxRegimes::EXPORT_SERVICES => ['code' => '3000', 'label' => 'Income / (Loss) from Business'],
        TaxRegimes::CAPITAL_GAINS => ['code' => '4000', 'label' => 'Gains / (Loss) from Capital Assets'],
    ];

    /**
     * Personal chart code => the IRIS wealth-statement code its balance declares
     * under. The 7000-series a 114(1) prints; anything unmapped falls to 7015
     * (assets) or 7021 (liabilities) rather than being dropped.
     *
     * @var array<string, array{code: string, label: string}>
     */
    private const WEALTH_CODES = [
        '1000' => ['code' => '7012', 'label' => 'Cash in hand'],
        '1100' => ['code' => '7006', 'label' => 'Investments / Stocks / Bonds / Bank balances'],
        '1200' => ['code' => '7006', 'label' => 'Investments / Stocks / Bonds / Bank balances'],
        '1500' => ['code' => '7006', 'label' => 'Investments / Stocks / Bonds / Bank balances'],
        '1400' => ['code' => '7109', 'label' => 'Residential / other property'],
        '1450' => ['code' => '7008', 'label' => 'Motor vehicle(s)'],
        '1600' => ['code' => '7015', 'label' => 'Any other asset (advance / withheld tax)'],
    ];

    public function __construct(private PersonalTaxService $tax) {}

    /**
     * @return array<string, mixed>
     */
    public function build(int $fiscalYearId): array
    {
        $year = FiscalYear::findOrFail($fiscalYearId);

        $income = $this->tax->estimate($fiscalYearId);
        $heads = $this->heads($income['regimes']);
        $withholding = $this->withholdingBySection($fiscalYearId);
        $taxPaid = round(array_sum(array_column($withholding, 'tax')), 2);
        $wealth = $this->wealthStatement($year);
        $expenses = $this->expensesIn($fiscalYearId);

        $totalIncome = round($income['total_income'], 2);
        $normalIncome = round(collect($income['regimes'])->reject(fn (array $r): bool => TaxRegimes::isFinal($r['regime']))->sum('income'), 2);
        $finalIncome = round($totalIncome - $normalIncome, 2);
        $finalTax = round(collect($income['regimes'])->filter(fn (array $r): bool => TaxRegimes::isFinal($r['regime']))->sum(fn (array $r): float => $r['total']), 2);
        $normalTax = round(collect($income['regimes'])->reject(fn (array $r): bool => TaxRegimes::isFinal($r['regime']))->sum(fn (array $r): float => $r['total']), 2);
        $chargeable = round($normalTax + $finalTax, 2);

        // Inflows include unclassified income: it is still money that arrived, and a
        // reconciliation that ignored it would report real income as unexplained wealth.
        $inflows = round($totalIncome + $income['unclassified'], 2);
        $expectedClosing = round($wealth['opening_net'] + $inflows - $expenses, 2);

        return [
            'year' => $year,
            'income' => $income,
            'heads' => $heads,

            // The Computations block, in the return's own order and codes.
            'computations' => [
                ['code' => '9000', 'label' => 'Total Income', 'amount' => $totalIncome, 'final' => $finalIncome, 'normal' => $normalIncome],
                ['code' => '9100', 'label' => 'Taxable Income', 'amount' => $normalIncome, 'final' => null, 'normal' => $normalIncome],
                ['code' => '920100', 'label' => 'Fixed / Final Tax', 'amount' => $finalTax, 'final' => $finalTax, 'normal' => null],
                ['code' => '9200', 'label' => 'Tax Chargeable', 'amount' => $chargeable, 'final' => null, 'normal' => null],
                ['code' => '9201', 'label' => 'Withholding Income Tax', 'amount' => $taxPaid, 'final' => null, 'normal' => null],
                ['code' => '9203', 'label' => $chargeable - $taxPaid >= 0 ? 'Admitted Income Tax' : 'Refundable Income Tax', 'amount' => round(abs($chargeable - $taxPaid), 2), 'final' => null, 'normal' => null],
            ],

            // The one 9201 total broken out the way IRIS itemises it — by section.
            'withholding_by_section' => $withholding,

            'tax_chargeable' => $chargeable,
            'normal_tax' => $normalTax,
            'final_tax' => $finalTax,
            'tax_paid' => $taxPaid,
            // Positive: pay with the return (9203 Admitted). Negative: claim the refund.
            'balance' => round($chargeable - $taxPaid, 2),

            'expenses' => $expenses,
            'wealth' => $wealth,

            // Reconciliation of Net Assets, coded as the return codes it.
            'reconciliation' => [
                ['code' => '703001', 'label' => 'Net Assets Current Year', 'amount' => $wealth['closing_net']],
                ['code' => '703002', 'label' => 'Net Assets Previous Year', 'amount' => $wealth['opening_net']],
                ['code' => '703003', 'label' => 'Increase / Decrease in Assets', 'amount' => round($wealth['closing_net'] - $wealth['opening_net'], 2)],
                ['code' => '7049', 'label' => 'Inflows', 'amount' => $inflows],
                ['code' => '7031', 'label' => 'Income Declared as per Return subject to Normal Tax', 'amount' => round($normalIncome + $income['unclassified'], 2)],
                ['code' => '7033', 'label' => 'Income Attributable to Receipts subject to Final / Fixed Tax', 'amount' => $finalIncome],
                ['code' => '7099', 'label' => 'Outflows', 'amount' => $expenses],
                ['code' => '7089', 'label' => 'Personal Expenses', 'amount' => $expenses],
                ['code' => '703000', 'label' => 'Unreconciled Amount', 'amount' => round($wealth['closing_net'] - $expectedClosing, 2)],
            ],
            'unreconciled' => round($wealth['closing_net'] - $expectedClosing, 2),
        ];
    }

    /**
     * The income heads as the return prints them: one row per IRIS head, each
     * regime's figures folded into its head's four columns.
     *
     * @param  array<int, array<string, mixed>>  $regimes
     * @return array<int, array<string, mixed>>
     */
    private function heads(array $regimes): array
    {
        $heads = [];

        foreach ($regimes as $row) {
            $head = self::HEADS[$row['regime']] ?? ['code' => '5000', 'label' => 'Income / (Loss) from Other Sources'];
            $isFinal = TaxRegimes::isFinal($row['regime']);

            $entry = $heads[$head['code']] ?? [
                'code' => $head['code'],
                'label' => $head['label'],
                'total' => 0.0, 'final' => 0.0, 'exempt' => 0.0, 'normal' => 0.0, 'tax' => 0.0,
            ];

            $entry['total'] = round($entry['total'] + $row['income'], 2);
            $entry[$isFinal ? 'final' : 'normal'] = round($entry[$isFinal ? 'final' : 'normal'] + $row['income'], 2);
            $entry['tax'] = round($entry['tax'] + $row['total'], 2);

            $heads[$head['code']] = $entry;
        }

        ksort($heads);

        return array_values($heads);
    }

    /**
     * Tax withheld or paid in advance during the year, by IRIS section — the
     * in-year movement of each withholding account, net of corrections. Sections
     * with no movement are dropped; an account a filer never created simply does
     * not appear, which understates nothing the books know about.
     *
     * @return array<int, array{code: string, section: string, tax: float}>
     */
    private function withholdingBySection(int $fiscalYearId): array
    {
        $rows = [];

        foreach (self::WITHHOLDING_SECTIONS as $code => $section) {
            // PHP casts numeric-string array keys to int, so restore the string code.
            $code = (string) $code;
            $account = Account::query()->where('code', $code)->first();

            if (! $account) {
                continue;
            }

            $lines = JournalEntryLine::query()
                ->where('account_id', $account->id)
                ->whereHas('journalEntry', fn ($query) => $query
                    ->where('is_posted', true)
                    ->where('fiscal_year_id', $fiscalYearId));

            $tax = round((float) (clone $lines)->sum('debit_amount') - (float) $lines->sum('credit_amount'), 2);

            if (abs($tax) >= 0.005) {
                $rows[] = ['code' => $code, 'section' => $section, 'tax' => $tax];
            }
        }

        return $rows;
    }

    /**
     * The year's personal spending: in-year movement of every expense account.
     */
    private function expensesIn(int $fiscalYearId): float
    {
        $lines = JournalEntryLine::query()
            ->whereIn('account_id', Account::ofType('expense')->select('id'))
            ->whereHas('journalEntry', fn ($query) => $query
                ->where('is_posted', true)
                ->where('fiscal_year_id', $fiscalYearId));

        return round((float) (clone $lines)->sum('debit_amount') - (float) $lines->sum('credit_amount'), 2);
    }

    /**
     * Assets and liabilities at both ends of the year, each row carrying the IRIS
     * wealth code it declares under. Rows with no movement and no balance dropped
     * rather than printed as noise.
     *
     * @return array{assets: array<int, array<string, mixed>>, liabilities: array<int, array<string, mixed>>, opening_net: float, closing_net: float}
     */
    private function wealthStatement(FiscalYear $year): array
    {
        $statement = ['assets' => [], 'liabilities' => [], 'opening_net' => 0.0, 'closing_net' => 0.0];

        foreach (['assets' => 'asset', 'liabilities' => 'liability'] as $side => $type) {
            foreach (Account::ofType($type)->orderBy('code')->get() as $account) {
                $opening = $this->balanceBefore($account, $year->start_date->toDateString());
                $closing = $this->balanceThrough($account, $year->end_date->toDateString());

                if (abs($opening) < 0.005 && abs($closing) < 0.005) {
                    continue;
                }

                $iris = self::WEALTH_CODES[$account->code]
                    ?? ($type === 'asset'
                        ? ['code' => '7015', 'label' => 'Any other asset']
                        : ['code' => '7021', 'label' => 'Personal liabilities']);

                $statement[$side][] = [
                    'code' => $account->code,
                    'name' => $account->name,
                    'iris_code' => $iris['code'],
                    'iris_label' => $iris['label'],
                    'opening' => $opening,
                    'closing' => $closing,
                ];

                $sign = $type === 'asset' ? 1 : -1;
                $statement['opening_net'] = round($statement['opening_net'] + $sign * $opening, 2);
                $statement['closing_net'] = round($statement['closing_net'] + $sign * $closing, 2);
            }
        }

        return $statement;
    }

    private function balanceBefore(Account $account, string $date): float
    {
        return $this->balance($account, fn ($query) => $query->whereDate('entry_date', '<', $date));
    }

    private function balanceThrough(Account $account, string $date): float
    {
        return $this->balance($account, fn ($query) => $query->whereDate('entry_date', '<=', $date));
    }

    /** Posted lines only, across every year up to the cut — wealth accumulates. */
    private function balance(Account $account, callable $constraint): float
    {
        $lines = JournalEntryLine::query()
            ->where('account_id', $account->id)
            ->whereHas('journalEntry', fn ($query) => $constraint($query->where('is_posted', true)));

        $debits = (float) (clone $lines)->sum('debit_amount');
        $credits = (float) $lines->sum('credit_amount');

        return round($account->normal_balance === 'debit' ? $debits - $credits : $credits - $debits, 2);
    }
}
