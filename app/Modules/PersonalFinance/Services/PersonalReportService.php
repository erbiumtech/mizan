<?php

namespace App\Modules\PersonalFinance\Services;

use App\Modules\PersonalFinance\Models\PersonalAccount;

/**
 * A person's balance sheet and their income against their spending.
 *
 * The section signing follows FinancialReportService: each side is reported
 * positive from its own point of view, so a liability of 50,000 reads as 50,000
 * rather than -50,000. Balances come from PersonalAccount::balance(), which sums
 * the lines — there is no cached column to fall out of step with.
 *
 * Every query here runs through the owner global scope, so "the balance sheet"
 * always means the signed-in person's.
 */
class PersonalReportService
{
    /**
     * What they own, what they owe, and the difference.
     *
     * @return array{assets: array<int, array{name: string, code: string, balance: float}>, liabilities: array<int, array{name: string, code: string, balance: float}>, total_assets: float, total_liabilities: float, net_worth: float}
     */
    public function balanceSheet(): array
    {
        $assets = $this->section(PersonalAccount::TYPE_ASSET);
        $liabilities = $this->section(PersonalAccount::TYPE_LIABILITY);

        $totalAssets = round(array_sum(array_column($assets, 'balance')), 2);
        $totalLiabilities = round(array_sum(array_column($liabilities, 'balance')), 2);

        return [
            'assets' => $assets,
            'liabilities' => $liabilities,
            'total_assets' => $totalAssets,
            'total_liabilities' => $totalLiabilities,
            'net_worth' => round($totalAssets - $totalLiabilities, 2),
        ];
    }

    /**
     * Earnings against spending for one tax year, by category.
     *
     * Restricted to entries stamped with that fiscal year, which is what makes
     * "how much went on education this year" answerable.
     *
     * @return array{income: array<int, array{name: string, code: string, amount: float}>, expenses: array<int, array{name: string, code: string, amount: float}>, total_income: float, total_expenses: float, surplus: float}
     */
    public function incomeAndExpenditure(?int $fiscalYearId): array
    {
        $income = $this->movement(PersonalAccount::TYPE_INCOME, $fiscalYearId);
        $expenses = $this->movement(PersonalAccount::TYPE_EXPENSE, $fiscalYearId);

        $totalIncome = round(array_sum(array_column($income, 'amount')), 2);
        $totalExpenses = round(array_sum(array_column($expenses, 'amount')), 2);

        return [
            'income' => $income,
            'expenses' => $expenses,
            'total_income' => $totalIncome,
            'total_expenses' => $totalExpenses,
            // Negative means they spent more than they earned, which is worth
            // seeing rather than hiding behind an abs().
            'surplus' => round($totalIncome - $totalExpenses, 2),
        ];
    }

    /**
     * @return array<int, array{name: string, code: string, balance: float}>
     */
    private function section(string $type): array
    {
        $rows = [];

        foreach (PersonalAccount::ofType($type)->orderBy('code')->get() as $account) {
            $balance = $account->balance();

            // A closed account with nothing left in it is noise; one that still
            // holds a balance has to be shown or the sheet will not add up.
            if (abs($balance) < 0.005 && ! $account->is_active) {
                continue;
            }

            $rows[] = [
                'code' => (string) $account->code,
                'name' => (string) $account->name,
                'balance' => $balance,
            ];
        }

        return $rows;
    }

    /**
     * @return array<int, array{name: string, code: string, amount: float}>
     */
    private function movement(string $type, ?int $fiscalYearId): array
    {
        $rows = [];

        foreach (PersonalAccount::ofType($type)->orderBy('code')->get() as $account) {
            $lines = $account->lines()
                ->when($fiscalYearId, fn ($query) => $query->whereHas(
                    'entry',
                    fn ($entry) => $entry->where('fiscal_year_id', $fiscalYearId),
                ));

            $debits = (float) (clone $lines)->sum('debit');
            $credits = (float) (clone $lines)->sum('credit');

            // Income grows on credits, expense on debits. Reported positive
            // either way so the two columns can sit side by side.
            $amount = $type === PersonalAccount::TYPE_INCOME
                ? round($credits - $debits, 2)
                : round($debits - $credits, 2);

            if (abs($amount) < 0.005) {
                continue;
            }

            $rows[] = [
                'code' => (string) $account->code,
                'name' => (string) $account->name,
                'amount' => $amount,
            ];
        }

        return $rows;
    }
}
