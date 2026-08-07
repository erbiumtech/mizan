<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\Budget;
use App\Modules\Accounting\Models\BudgetLine;
use Illuminate\Support\Facades\DB;

/**
 * Turns the annual figure somebody types into the monthly rows the budget is
 * actually made of.
 *
 * Nobody wants to enter twelve numbers per account, and no useful budget report
 * can be built from one number per year. This is the piece in between.
 */
class BudgetService
{
    /**
     * The repeater's rows as [account_id => annual amount].
     *
     * Rows with no account are dropped rather than rejected: an empty row is
     * what a repeater leaves behind when somebody clicks "Add" and changes their
     * mind, and failing the save over it would be the form's fault, not theirs.
     *
     * @param  array<int|string, array{account_id?: int|string|null, amount?: mixed}>  $rows
     * @return array<int, float>
     */
    public function planFromForm(array $rows): array
    {
        $plan = [];

        foreach ($rows as $row) {
            $accountId = (int) ($row['account_id'] ?? 0);

            if ($accountId === 0) {
                continue;
            }

            $plan[$accountId] = round((float) ($row['amount'] ?? 0), 2);
        }

        return $plan;
    }

    /**
     * Replace the whole plan: [account_id => annual amount].
     *
     * Accounts absent from $plan are dropped, which is what makes removing a row
     * on the form remove it from the budget.
     *
     * @param  array<int, float>  $plan
     */
    public function syncAnnualPlan(Budget $budget, array $plan): void
    {
        DB::connection($budget->getConnectionName())->transaction(function () use ($budget, $plan) {
            $budget->lines()->whereNotIn('account_id', array_keys($plan) ?: [0])->delete();

            foreach ($plan as $accountId => $annual) {
                $this->setAnnual($budget, (int) $accountId, (float) $annual);
            }
        });
    }

    /**
     * Set one account's yearly figure, spreading it over the budget's months.
     *
     * Does nothing when the total is already right, and that is the point rather
     * than an optimisation: a budget whose December was raised by hand has a
     * yearly total unchanged from the evenly-spread one it started as, so
     * re-spreading on every save of the form would silently undo every
     * adjustment anybody had made. Saving a form you did not change must not
     * change your data.
     */
    public function setAnnual(Budget $budget, int $accountId, float $annual): void
    {
        $existing = $budget->lines()->where('account_id', $accountId);

        if ($existing->exists() && abs($budget->annualFor($accountId) - round($annual, 2)) < 0.005) {
            return;
        }

        $this->spreadEvenly($budget, $accountId, $annual);
    }

    /**
     * Write one row per month, dividing $annual as evenly as decimals allow.
     *
     * The remainder lands on the final month rather than being dropped, so the
     * twelve rows add back to exactly what was typed. Without it, 100,000 over
     * twelve months stores 99,999.96 and the budget quietly disagrees with
     * itself by four paisa — which is small, and is also the kind of thing that
     * makes somebody stop trusting the report.
     */
    public function spreadEvenly(Budget $budget, int $accountId, float $annual): void
    {
        $months = $budget->months();

        $budget->lines()->where('account_id', $accountId)->delete();

        if ($months === []) {
            return;
        }

        $annual = round($annual, 2);
        $per = round($annual / count($months), 2);
        $rows = [];

        foreach ($months as $index => $month) {
            $isLast = $index === count($months) - 1;

            $rows[] = [
                'budget_id' => $budget->getKey(),
                'account_id' => $accountId,
                'period_start' => $month->toDateString(),
                'amount' => $isLast
                    ? round($annual - ($per * (count($months) - 1)), 2)
                    : $per,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        BudgetLine::insert($rows);
    }

    /**
     * The plan as the form shows it: one row per account, in code order.
     *
     * Summed in PHP rather than by the database. A `GROUP BY account_id` that
     * also orders by `accounts.code` is rejected outright under MySQL's
     * ONLY_FULL_GROUP_BY, which is on by default and is not on in the sqlite the
     * suite runs against — so the query that passes here would be the one that
     * throws in production. A budget has a few dozen accounts; this is not the
     * place to be clever.
     *
     * @return array<int, array{account_id: int, amount: float}>
     */
    public function annualPlan(Budget $budget): array
    {
        return $budget->lines()
            ->with('account:id,code')
            ->get()
            ->groupBy('account_id')
            ->map(fn ($lines, $accountId): array => [
                'account_id' => (int) $accountId,
                'amount' => round((float) $lines->sum('amount'), 2),
                'code' => (string) ($lines->first()->account?->code ?? ''),
            ])
            ->sortBy('code')
            ->map(fn (array $row): array => ['account_id' => $row['account_id'], 'amount' => $row['amount']])
            ->values()
            ->all();
    }
}
