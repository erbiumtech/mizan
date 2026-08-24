<?php

namespace App\Modules\Employees\Support;

use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Models\EmployeeJobHistory;
use App\Support\Reporting\ReportPeriod;
use App\Support\Reporting\ReportShapes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Headcount movement and turnover — `docs/reports-expansion-plan.md` Phase 3.6.
 *
 * "Joiners and leavers per month from `employee_job_history` and `employees.leaving_date`, with turnover
 * percentage and average tenure."
 *
 * **There is no `employees.leaving_date`; the column is `left_on`.** Worth stating rather than silently
 * using the right one, because the plan's own data table is otherwise reliable and a reader checking this
 * against it will look for a column that does not exist.
 *
 * **Tenure is continuous service, which is not `date_of_joining`.** `FinalSettlementBuilder` already decided
 * this and its reason holds here: "somebody re-employed after a break has two spans and only the current one
 * counts", so service runs from the first job-history row and falls back to the joining date for the
 * employees who predate job history. A tenure figure that measured from the original joining date would
 * quietly reward the company for a gap in somebody's employment.
 *
 * **Joiners, though, are counted by when they joined.** A month's joiners is a fact about that month, and
 * re-employment is a joining — the person walked back through the door. So the two figures deliberately read
 * different columns, and the help says so.
 */
class HeadcountReports
{
    use ReportShapes;

    public function movement(string $asOf): array
    {
        $period = ReportPeriod::toDate($asOf);
        $from = Carbon::parse($period['from'])->startOfMonth();
        $to = Carbon::parse($period['to']);

        // Everybody, once. Headcount at the end of each month is a filter over this set rather than a query
        // per month, which is what a twelve-row report would otherwise cost.
        $employees = Employee::query()->get(['id', 'date_of_joining', 'left_on']);

        if ($employees->isEmpty()) {
            return $this->emptyMovement($period);
        }

        $serviceStart = $this->serviceStarts($employees);

        $rows = [];
        $joiners = 0;
        $leavers = 0;
        $tenures = [];

        for ($month = $from->copy(); $month->lte($to); $month = $month->addMonth()) {
            $monthStart = $month->copy()->startOfMonth();
            $monthEnd = $month->copy()->endOfMonth();

            // Dates, for the reason `headcountAt()` explains: these columns are date casts and the bounds
            // are instants, so anything comparing them directly is one time component away from being wrong
            // at a month boundary.
            $first = $monthStart->toDateString();
            $last = $monthEnd->toDateString();

            $joined = $employees->filter(fn (Employee $employee): bool => $employee->date_of_joining !== null
                && $employee->date_of_joining->toDateString() >= $first
                && $employee->date_of_joining->toDateString() <= $last)->count();

            $left = $employees->filter(fn (Employee $employee): bool => $employee->left_on !== null
                && $employee->left_on->toDateString() >= $first
                && $employee->left_on->toDateString() <= $last);

            $openingHeadcount = $this->headcountAt($employees, $monthStart->copy()->subDay());
            $closingHeadcount = $this->headcountAt($employees, $monthEnd);

            $monthTenures = $left
                ->map(fn (Employee $employee): ?float => $this->tenureYears($employee, $serviceStart))
                ->filter()
                ->values();

            $rows[] = [
                $monthEnd->format('F Y'),
                $joined > 0 ? number_format($joined) : '—',
                $left->count() > 0 ? number_format($left->count()) : '—',
                number_format($closingHeadcount),
                $this->turnover($left->count(), $openingHeadcount, $closingHeadcount),
                $monthTenures->isEmpty() ? '—' : number_format($monthTenures->avg(), 1),
            ];

            $joiners += $joined;
            $leavers += $left->count();
            $tenures = [...$tenures, ...$monthTenures->all()];
        }

        $closing = $this->headcountAt($employees, $to);
        $opening = $this->headcountAt($employees, $from->copy()->subDay());

        return $this->table(
            'HeadcountMovement',
            'Headcount Movement',
            $this->subtitle('between '.$period['from'].' and '.$period['to']),
            ['Month', 'Joiners', 'Leavers', 'Headcount', 'Turnover', 'Avg tenure'],
            'minmax(0, 1fr) 8rem 8rem 9rem 8rem 10rem',
            [1, 2, 3, 4, 5],
            $rows,
            [
                ['label' => 'HEADCOUNT', 'value' => (float) $closing, 'accent' => true],
                ['label' => 'LEAVERS', 'value' => (float) $leavers, 'accent' => false],
            ],
            $this->movementNote($opening, $closing, $joiners, $leavers, $tenures),
            $rows === [] ? null : [
                'Total — '.count($rows).' months',
                number_format($joiners),
                number_format($leavers),
                number_format($closing),
                $this->turnover($leavers, $opening, $closing),
                $tenures === [] ? '—' : number_format(array_sum($tenures) / count($tenures), 1),
            ],
            'Nobody is on the payroll.',
        );
    }

    /**
     * Turnover: leavers as a proportion of the *average* headcount.
     *
     * The average of opening and closing rather than either one, which is the conventional formula and the
     * only one that behaves at both ends: measured against opening headcount a company that halved would
     * report a rate below its real one, and against closing it would report an absurdly high one. A company
     * that ended the month with nobody left would divide by nought on the closing figure alone.
     *
     * A dash where the average is nought — no headcount is not nought per cent turnover, it is a company with
     * nobody in it.
     */
    private function turnover(int $leavers, int $opening, int $closing): string
    {
        $average = ($opening + $closing) / 2;

        return $average <= 0 ? '—' : number_format($leavers / $average * 100, 1).'%';
    }

    /**
     * How many people were employed on a date.
     *
     * Joined on or before it and either still there or left on or after it. Somebody who joined and left on
     * the same day counts on that day, which is the reading that keeps the joiner and leaver columns
     * consistent with the headcount between them.
     *
     * **Compared as date strings, not as Carbon instants**, and that is not tidiness. `left_on` is a `date`
     * cast, so it is midnight; the boundary handed in here is an `endOfMonth()`, so it is 23:59:59. Comparing
     * the two directly made somebody who left on the last day of a month vanish from that month's closing
     * headcount — which then halved the turnover denominator and reported 200% for a month in which one
     * person of one left. Both failures came from the same time component, and dates are what the question
     * is actually about.
     *
     * @param  Collection<int, Employee>  $employees
     */
    private function headcountAt(Collection $employees, Carbon $on): int
    {
        $date = $on->toDateString();

        return $employees
            ->filter(fn (Employee $employee): bool => $employee->date_of_joining !== null
                && $employee->date_of_joining->toDateString() <= $date
                && ($employee->left_on === null || $employee->left_on->toDateString() >= $date))
            ->count();
    }

    /**
     * Continuous service in years, to one decimal.
     *
     * From the first job-history row, or the joining date for anybody who predates job history. Null where
     * neither is known — which is a real state for an imported employee, and averaging a nought into the
     * tenure figure would drag it down for a missing record rather than a short career.
     *
     * @param  array<int, Carbon>  $serviceStart
     */
    private function tenureYears(Employee $employee, array $serviceStart): ?float
    {
        $start = $serviceStart[$employee->getKey()] ?? $employee->date_of_joining;

        if ($start === null || $employee->left_on === null) {
            return null;
        }

        return round($start->floatDiffInYears($employee->left_on), 2);
    }

    /**
     * The first job-history row per employee, in one query.
     *
     * Ascending by `effective_from` and keyed on the employee, so the first row seen per employee is the
     * earliest — `keyBy` keeps the last of a duplicate key, so the order is reversed to make it keep the
     * first. Subtle enough to state: sorted the other way this would silently measure tenure from somebody's
     * most recent promotion.
     *
     * @param  Collection<int, Employee>  $employees
     * @return array<int, Carbon>
     */
    private function serviceStarts(Collection $employees): array
    {
        return EmployeeJobHistory::query()
            ->whereIn('employee_id', $employees->modelKeys())
            ->orderByDesc('effective_from')
            ->get(['employee_id', 'effective_from'])
            ->keyBy('employee_id')
            ->map(fn (EmployeeJobHistory $row): Carbon => Carbon::parse($row->effective_from))
            ->all();
    }

    /**
     * The movement in one sentence.
     *
     * Net change first, because that is the question somebody opens a headcount report to answer, and the
     * turnover rate second — a company that grew by ten while losing eight has a story neither figure tells
     * alone.
     *
     * @param  array<int, float>  $tenures
     */
    private function movementNote(int $opening, int $closing, int $joiners, int $leavers, array $tenures): string
    {
        $net = $closing - $opening;

        return mb_strtoupper(implode(' · ', array_filter([
            $opening.' to '.$closing.' ('.($net >= 0 ? '+' : '').$net.')',
            $joiners.' joined, '.$leavers.' left',
            'turnover '.$this->turnover($leavers, $opening, $closing),
            $tenures !== []
                ? 'leavers averaged '.number_format(array_sum($tenures) / count($tenures), 1).' years'
                : null,
        ])));
    }

    /**
     * @param  array{from: string, to: string}  $period
     * @return array<string, mixed>
     */
    private function emptyMovement(array $period): array
    {
        return $this->table(
            'HeadcountMovement',
            'Headcount Movement',
            $this->subtitle('between '.$period['from'].' and '.$period['to']),
            ['Month', 'Joiners', 'Leavers', 'Headcount', 'Turnover', 'Avg tenure'],
            'minmax(0, 1fr) 8rem 8rem 9rem 8rem 10rem',
            [1, 2, 3, 4, 5],
            [],
            [
                ['label' => 'HEADCOUNT', 'value' => 0.0, 'accent' => true],
                ['label' => 'LEAVERS', 'value' => 0.0, 'accent' => false],
            ],
            'NOBODY IS ON THE PAYROLL',
            null,
            'Nobody is on the payroll.',
        );
    }
}
