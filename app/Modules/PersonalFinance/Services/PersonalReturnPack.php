<?php

namespace App\Modules\PersonalFinance\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Core\Models\FiscalYear;

/**
 * Everything a person types into their IRIS return, assembled from their own books.
 *
 * FBR exposes no API for filing an income tax return — the sanctioned mechanisms are
 * the IRIS portal and a registered e-intermediary — so this deliberately prepares and
 * never transmits: the pack holds each figure under the heading IRIS asks for it by,
 * and filing is transcription instead of a day of extraction. Three statements:
 *
 *  - **Income and tax** — PersonalTaxService's estimate: income by regime, the
 *    bracket working, surcharge, total chargeable.
 *  - **Tax already paid** — the in-year movement of the `Advance & Withheld Tax`
 *    account (1600, an asset: until the return settles it is a claim against the
 *    liability). Chargeable minus paid is the balance IRIS will show as payable
 *    or refundable.
 *  - **The wealth statement** — every asset and liability at both ends of the year,
 *    and the reconciliation IRIS calls by that name: opening net assets plus income
 *    less personal expenses should equal closing. What does not reconcile is shown
 *    as exactly that, because unexplained movement in wealth is the question a
 *    return gets asked, and this screen is where to find it before FBR does.
 *
 * Balances at a date are computed over ALL posted lines before it, not one year's —
 * wealth accumulates across years; income and expenses are the year's own. Same
 * posted-only rule as every other report here.
 */
class PersonalReturnPack
{
    /** The chart code the seeder gives the withheld/advance tax asset. */
    public const TAX_PAID_ACCOUNT_CODE = '1600';

    public function __construct(private PersonalTaxService $tax) {}

    /**
     * @return array<string, mixed>
     */
    public function build(int $fiscalYearId): array
    {
        $year = FiscalYear::findOrFail($fiscalYearId);

        $income = $this->tax->estimate($fiscalYearId);
        $taxPaid = $this->taxPaidIn($fiscalYearId);
        $wealth = $this->wealthStatement($year);
        $expenses = $this->expensesIn($fiscalYearId);

        // Inflows include unclassified income: it is still money that arrived, and a
        // reconciliation that ignored it would report real income as unexplained wealth.
        $inflows = round($income['total_income'] + $income['unclassified'], 2);
        $expectedClosing = round($wealth['opening_net'] + $inflows - $expenses, 2);

        return [
            'year' => $year,
            'income' => $income,
            'tax_paid' => $taxPaid,
            // Positive: pay with the return. Negative: claim the refund.
            'balance' => round(($income['total_payable'] ?? 0) - $taxPaid, 2),
            'expenses' => $expenses,
            'wealth' => $wealth,
            'reconciliation' => [
                'opening_net' => $wealth['opening_net'],
                'inflows' => $inflows,
                'expenses' => $expenses,
                'expected_closing' => $expectedClosing,
                'actual_closing' => $wealth['closing_net'],
                // Non-zero means wealth moved outside the income and expense
                // accounts — an opening-balance entry, a gift, a forgotten
                // posting. That is the figure to explain before filing, not
                // after; tax paid does not appear because in these books it is
                // an asset swap, not consumption.
                'unexplained' => round($wealth['closing_net'] - $expectedClosing, 2),
            ],
        ];
    }

    /**
     * Tax withheld or paid in advance during the year: the in-year debits of the
     * 1600 account, net of corrections. Reads the code the seeder writes; an
     * account somebody deleted simply reports zero paid, which understates
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
     * Assets and liabilities at both ends of the year, rows with no movement and
     * no balance dropped rather than printed as noise.
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

                $statement[$side][] = [
                    'code' => $account->code,
                    'name' => $account->name,
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
