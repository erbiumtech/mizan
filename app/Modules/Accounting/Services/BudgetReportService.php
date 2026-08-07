<?php

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Budget;
use App\Modules\Accounting\Models\JournalEntryLine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Plan against actual, for one budget over one period.
 *
 * The actuals side reuses the definition the Profit & Loss uses — posted lines
 * only, signed by the account's normal balance — so a budget report and a P&L
 * over the same dates cannot show different spending. What is new here is the
 * plan side, and two decisions in it are worth stating because they are the
 * ones that make a budget report either honest or flattering:
 *
 *  - A PART MONTH IS COUNTED AS A PART MONTH. Ask on 7 August how the year is
 *    going and the plan you are measured against is July plus seven days of
 *    August, not July plus all of August. Counting the whole month is the
 *    ordinary way an overspend is made to look like an underspend, and it does
 *    it silently, every time, until the month ends.
 *
 *  - VARIANCE IS SIGNED BY WHETHER IT IS GOOD NEWS, not by arithmetic. Spending
 *    less than planned and earning more than planned are both favourable, and
 *    they are opposite subtractions. A single signed column that means "ahead"
 *    for income and "behind" for expenses is a column nobody can read.
 */
class BudgetReportService
{
    /**
     * @return array{
     *     budget: Budget, from: string, to: string,
     *     sections: array<int, array{type: string, rows: array<int, array<string, mixed>>, planned: float, actual: float, variance: float, favourable: bool}>,
     *     monthly: array<int, array{month: string, label: string, planned: float, actual: float|null}>,
     *     net_planned: float, net_actual: float, has_plan: bool
     * }
     */
    public function report(Budget $budget, ?string $from = null, ?string $to = null): array
    {
        $months = $budget->months();
        $year = $budget->fiscalYear;

        $from = CarbonImmutable::parse($from ?? $year?->start_date ?? now())->startOfDay();
        $to = CarbonImmutable::parse($to ?? now())->startOfDay();

        // A "to" beyond the year the budget plans would add actuals it has no
        // plan for and report them as pure overspend.
        if ($year?->end_date !== null) {
            $yearEnd = CarbonImmutable::parse($year->end_date)->startOfDay();
            $to = $to->greaterThan($yearEnd) ? $yearEnd : $to;
        }

        // A backwards range is a typo on the form, not a request for negative
        // figures. Report the empty window it describes.
        $to = $to->lessThan($from) ? $from->subDay() : $to;

        $lines = $budget->lines()->get();
        $accounts = $this->accountsInvolved($budget, $lines);

        $planned = $this->plannedByAccount($lines, $from, $to);
        $actual = $this->actualByAccount($accounts, $months, $from, $to);
        $fullYear = $this->plannedByAccount($lines, null, null);

        $sections = [];

        foreach (['income', 'expense'] as $type) {
            $rows = [];

            foreach ($accounts->where('type', $type)->sortBy('code') as $account) {
                $p = round($planned[$account->id] ?? 0.0, 2);
                $a = round($actual[$account->id] ?? 0.0, 2);
                $y = round($fullYear[$account->id] ?? 0.0, 2);

                // An account with neither a plan nor any activity is not news.
                if (abs($p) < 0.005 && abs($a) < 0.005 && abs($y) < 0.005) {
                    continue;
                }

                $rows[] = [
                    'account_id' => $account->id,
                    'code' => $account->code,
                    'name' => $account->name,
                    'full_year' => $y,
                    'planned' => $p,
                    'actual' => $a,
                    'variance' => $this->variance($type, $p, $a),
                    'used_percent' => $this->usedPercent($y, $a),
                    'unplanned' => abs($y) < 0.005 && abs($a) >= 0.005,
                ];
            }

            $sectionPlanned = round(array_sum(array_column($rows, 'planned')), 2);
            $sectionActual = round(array_sum(array_column($rows, 'actual')), 2);

            $sections[] = [
                'type' => $type,
                'rows' => $rows,
                'full_year' => round(array_sum(array_column($rows, 'full_year')), 2),
                'planned' => $sectionPlanned,
                'actual' => $sectionActual,
                'variance' => $this->variance($type, $sectionPlanned, $sectionActual),
                'favourable' => $this->variance($type, $sectionPlanned, $sectionActual) >= 0,
            ];
        }

        $income = collect($sections)->firstWhere('type', 'income');
        $expense = collect($sections)->firstWhere('type', 'expense');

        return [
            'budget' => $budget,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'sections' => $sections,
            'monthly' => $this->monthlySeries($budget, $lines, $accounts, $months, $to),
            'net_planned' => round($income['planned'] - $expense['planned'], 2),
            'net_actual' => round($income['actual'] - $expense['actual'], 2),
            'has_plan' => $lines->isNotEmpty(),
        ];
    }

    /**
     * Favourable-positive. Spending under plan and earning over it both read as
     * a positive number; both kinds of bad news read as negative.
     */
    private function variance(string $type, float $planned, float $actual): float
    {
        return round($type === 'income' ? $actual - $planned : $planned - $actual, 2);
    }

    /**
     * How much of the year's plan has been used, as a percentage.
     *
     * Null rather than zero when there is no plan: "0% of nothing" and "none of
     * the budget used" are different facts, and a column showing 0% against an
     * account somebody has already spent on is the more misleading of the two.
     */
    private function usedPercent(float $fullYear, float $actual): ?float
    {
        return abs($fullYear) < 0.005 ? null : round($actual / $fullYear * 100, 1);
    }

    /**
     * Every account the report has something to say about: the ones planned for,
     * plus any income or expense account with activity in the year that nobody
     * budgeted. The second half is the more useful one — an unbudgeted cost is
     * exactly what a budget review is looking for, and listing only what was
     * planned hides it by construction.
     */
    private function accountsInvolved(Budget $budget, Collection $lines): Collection
    {
        $planned = $lines->pluck('account_id')->unique()->all();
        $active = $this->accountsWithActivity($budget);

        return Account::query()
            ->whereIn('type', ['income', 'expense'])
            ->whereIn('id', array_values(array_unique([...$planned, ...$active])) ?: [0])
            ->get()
            ->keyBy('id');
    }

    /** @return array<int, int> */
    private function accountsWithActivity(Budget $budget): array
    {
        $year = $budget->fiscalYear;

        if ($year?->start_date === null || $year->end_date === null) {
            return [];
        }

        return JournalEntryLine::query()
            ->whereHas('journalEntry', function ($q) use ($year) {
                $q->where('is_posted', true)
                    ->whereDate('entry_date', '>=', $year->start_date)
                    ->whereDate('entry_date', '<=', $year->end_date);
            })
            ->distinct()
            ->pluck('account_id')
            ->all();
    }

    /**
     * Planned amount per account over [$from, $to], counting part months by the
     * days they contribute. Passing nulls gives the untrimmed yearly figure.
     *
     * @return array<int, float>
     */
    private function plannedByAccount(Collection $lines, ?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        $planned = [];

        foreach ($lines as $line) {
            $monthStart = CarbonImmutable::parse($line->period_start)->startOfDay();
            $monthEnd = $monthStart->endOfMonth()->startOfDay();

            $fraction = ($from === null && $to === null)
                ? 1.0
                : $this->overlapFraction($monthStart, $monthEnd, $from, $to);

            if ($fraction <= 0) {
                continue;
            }

            $planned[$line->account_id] = ($planned[$line->account_id] ?? 0.0)
                + ((float) $line->amount * $fraction);
        }

        return $planned;
    }

    /**
     * What share of a month falls inside the reporting window, by whole days.
     *
     * Inclusive at both ends, so a window of exactly one month is 1.0 rather
     * than 30/31 of itself.
     */
    private function overlapFraction(
        CarbonImmutable $monthStart,
        CarbonImmutable $monthEnd,
        ?CarbonImmutable $from,
        ?CarbonImmutable $to,
    ): float {
        $start = ($from !== null && $from->greaterThan($monthStart)) ? $from : $monthStart;
        $end = ($to !== null && $to->lessThan($monthEnd)) ? $to : $monthEnd;

        if ($start->greaterThan($end)) {
            return 0.0;
        }

        return ($start->diffInDays($end) + 1) / ($monthStart->daysInMonth);
    }

    /**
     * Actual movement per account over the window.
     *
     * One aggregate query per month rather than one grouped by month for the
     * whole range, because grouping rows into months in SQL means DATE_FORMAT on
     * MySQL and strftime on sqlite — and the suite runs on sqlite while
     * customers run on MySQL, so the portable-looking version is the one that
     * gets shipped untested. Twelve bounded queries for a report nobody opens in
     * a loop is the cheaper mistake.
     *
     * @param  array<int, CarbonImmutable>  $months
     * @return array<int, float>
     */
    private function actualByAccount(Collection $accounts, array $months, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $totals = [];

        foreach ($this->monthlyActuals($accounts, $months, $from, $to) as $perAccount) {
            foreach ($perAccount as $accountId => $amount) {
                $totals[$accountId] = ($totals[$accountId] ?? 0.0) + $amount;
            }
        }

        return $totals;
    }

    /**
     * Actuals for each month of the window, keyed month => [account_id => amount].
     *
     * @param  array<int, CarbonImmutable>  $months
     * @return array<string, array<int, float>>
     */
    private function monthlyActuals(Collection $accounts, array $months, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($accounts->isEmpty()) {
            return [];
        }

        $byMonth = [];

        foreach ($months as $month) {
            $start = $month->greaterThan($from) ? $month : $from;
            $end = $month->endOfMonth()->startOfDay();
            $end = $end->lessThan($to) ? $end : $to;

            if ($start->greaterThan($end)) {
                continue;
            }

            $rows = JournalEntryLine::query()
                ->whereIn('account_id', $accounts->keys()->all())
                ->whereHas('journalEntry', function ($q) use ($start, $end) {
                    $q->where('is_posted', true)
                        ->whereDate('entry_date', '>=', $start->toDateString())
                        ->whereDate('entry_date', '<=', $end->toDateString());
                })
                ->groupBy('account_id')
                ->selectRaw('account_id, SUM(debit_amount) as debits, SUM(credit_amount) as credits')
                ->get();

            $perAccount = [];

            foreach ($rows as $row) {
                $account = $accounts->get((int) $row->account_id);

                if ($account === null) {
                    continue;
                }

                $perAccount[(int) $row->account_id] = $account->normal_balance === 'debit'
                    ? (float) $row->debits - (float) $row->credits
                    : (float) $row->credits - (float) $row->debits;
            }

            $byMonth[$month->toDateString()] = $perAccount;
        }

        return $byMonth;
    }

    /**
     * Plan and actual per month, for the chart and the month-by-month table.
     *
     * Net figures (income less expense), which is what a "are we ahead or
     * behind" line is asking. Months after the reporting date are still listed
     * with their plan and a null actual, so the chart shows the rest of the year
     * as unspent rather than as a collapse to zero.
     *
     * @param  array<int, CarbonImmutable>  $months
     * @return array<int, array{month: string, label: string, planned: float, actual: float|null}>
     */
    private function monthlySeries(Budget $budget, Collection $lines, Collection $accounts, array $months, CarbonImmutable $to): array
    {
        $actuals = $this->monthlyActuals(
            $accounts,
            $months,
            CarbonImmutable::parse($budget->fiscalYear?->start_date ?? $months[0] ?? now()),
            $to,
        );

        // Grouped once rather than rescanned per month: twelve passes over every
        // line of a budget is the sort of loop that is invisible at ten accounts
        // and noticeable at two hundred.
        $planByMonth = $lines->groupBy(fn ($line): string => CarbonImmutable::parse($line->period_start)->toDateString());

        $series = [];

        foreach ($months as $month) {
            $key = $month->toDateString();

            $plannedIncome = 0.0;
            $plannedExpense = 0.0;

            foreach ($planByMonth->get($key, collect()) as $line) {
                $type = $accounts->get((int) $line->account_id)?->type;

                if ($type === 'income') {
                    $plannedIncome += (float) $line->amount;
                } elseif ($type === 'expense') {
                    $plannedExpense += (float) $line->amount;
                }
            }

            $actualIncome = 0.0;
            $actualExpense = 0.0;

            foreach ($actuals[$key] ?? [] as $accountId => $amount) {
                if ($accounts->get($accountId)?->type === 'income') {
                    $actualIncome += $amount;
                } else {
                    $actualExpense += $amount;
                }
            }

            $series[] = [
                'month' => $key,
                'label' => $month->format('M Y'),
                'planned' => round($plannedIncome - $plannedExpense, 2),
                'planned_income' => round($plannedIncome, 2),
                'planned_expense' => round($plannedExpense, 2),
                'actual' => array_key_exists($key, $actuals)
                    ? round($actualIncome - $actualExpense, 2)
                    : null,
                'actual_income' => round($actualIncome, 2),
                'actual_expense' => round($actualExpense, 2),
            ];
        }

        return $series;
    }
}
