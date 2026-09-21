<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Budget;
use App\Modules\Accounting\Models\BudgetLine;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Core\Models\FiscalYear;
use Illuminate\Support\Carbon;

/**
 * A budget that argues back — `docs/erpnext-gap-plan.md` §4 item 8, the warn-only half.
 *
 * `BudgetService` plans and `BudgetVsActual` reports; nothing said anything while the money was being
 * committed. ERPNext stops or warns at material request, purchase order and actual expense, annually and per
 * period. This is the warning, at the one document a company that is not a builder commits spend with — the
 * supplier's bill — and it never blocks: a bill is a fact about money already owed, and refusing to book it
 * would make the ledger wrong to keep the budget right.
 *
 * **No new table.** The plan is `budget_lines`, the actuals are the posted ledger, and the answer is a
 * sentence. Checked twice, month and year, because both are how a budget is read: a month may absorb one
 * large bill inside an annual figure, and a year may be spent by October with every month looking fine.
 */
class BudgetControl
{
    /**
     * What would be over budget if these charges were booked on this date.
     *
     * @param  array<int, array{0: int, 1: float}>  $charges  [account id, amount] pairs — a bill's expense lines
     * @return array<int, string> one sentence per account and period that would be exceeded; empty when nothing is
     */
    public function warningsFor(array $charges, string $date): array
    {
        $on = Carbon::parse($date);

        $year = FiscalYear::query()
            ->whereDate('start_date', '<=', $on->toDateString())
            ->whereDate('end_date', '>=', $on->toDateString())
            ->first();

        if ($year === null) {
            return [];
        }

        $budgets = Budget::query()->where('fiscal_year_id', $year->getKey())->where('is_active', true)->get();

        if ($budgets->isEmpty()) {
            return [];
        }

        // Summed per account first: a bill with two lines on the same account is one charge to it.
        $byAccount = [];

        foreach ($charges as [$accountId, $amount]) {
            $byAccount[(int) $accountId] = round(($byAccount[(int) $accountId] ?? 0.0) + (float) $amount, 2);
        }

        $monthStart = $on->copy()->startOfMonth()->toDateString();
        $monthEnd = $on->copy()->endOfMonth()->toDateString();
        $accounts = Account::query()->whereKey(array_keys($byAccount))->get()->keyBy('id');

        $warnings = [];

        foreach ($byAccount as $accountId => $charge) {
            $account = $accounts->get($accountId);

            // Only expenses are spent against. An inventory or asset line on a bill has no budget to exceed.
            if ($account === null || $account->type !== 'expense' || $charge <= 0) {
                continue;
            }

            $lines = BudgetLine::query()
                ->whereIn('budget_id', $budgets->modelKeys())
                ->where('account_id', $accountId)
                ->get();

            // An account nobody budgeted is not over budget; it is unplanned, which BudgetVsActual already
            // flags. Warning here too would make every bill to a new account a warning.
            if ($lines->isEmpty()) {
                continue;
            }

            $monthPlanned = round((float) $lines->filter(fn (BudgetLine $line): bool => $line->period_start->toDateString() === $monthStart)->sum('amount'), 2);
            $yearPlanned = round((float) $lines->sum('amount'), 2);

            $monthActual = $this->spent($accountId, $monthStart, $monthEnd);
            $yearActual = $this->spent($accountId, $year->start_date, $year->end_date);

            $label = "{$account->code} {$account->name}";

            if ($monthPlanned > 0 && round($monthActual + $charge, 2) > $monthPlanned + 0.005) {
                $warnings[] = sprintf(
                    '%s: %s spent in %s plus this %s is %s, against a budget of %s for the month.',
                    $label,
                    number_format($monthActual, 0),
                    $on->format('F'),
                    number_format($charge, 0),
                    number_format($monthActual + $charge, 0),
                    number_format($monthPlanned, 0),
                );
            }

            if (round($yearActual + $charge, 2) > $yearPlanned + 0.005) {
                $warnings[] = sprintf(
                    '%s: %s spent this year plus this %s is %s, against %s for the whole of %s.',
                    $label,
                    number_format($yearActual, 0),
                    number_format($charge, 0),
                    number_format($yearActual + $charge, 0),
                    number_format($yearPlanned, 0),
                    $year->name,
                );
            }
        }

        return $warnings;
    }

    /** Posted expense on one account between two dates: debits less credits, which is how expense reads. */
    private function spent(int $accountId, string $from, string $to): float
    {
        $sums = JournalEntryLine::query()
            ->where('account_id', $accountId)
            ->whereHas('journalEntry', fn ($query) => $query
                ->where('is_posted', true)
                ->whereDate('entry_date', '>=', $from)
                ->whereDate('entry_date', '<=', $to))
            ->selectRaw('COALESCE(SUM(debit_amount), 0) as d, COALESCE(SUM(credit_amount), 0) as c')
            ->first();

        return round((float) $sums->d - (float) $sums->c, 2);
    }
}
