<?php

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Models\AttendanceDay;
use App\Modules\Employees\Models\Employee;
use App\Support\Reporting\ReportPeriod;
use App\Support\TenantDb;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A month of attendance for everybody — `docs/reports-expansion-plan.md` Phase 3.1.
 *
 * "The company-wide aggregation of an existing figure — and the same figure payroll prorates on, which makes
 * disagreement between the two visible."
 *
 * **The grid is one query and the totals come from `AttendanceCalendar::summarise()`.** The statuses are read
 * in bulk because they are just rows; the totals are not, because `paidDays()` is `expected − absent` and
 * *expected* needs each employee's shift pattern and the holiday calendar. Re-deriving that here would put a
 * second answer to "was this a working day" beside payroll's, which is the one disagreement this report
 * exists to expose rather than to cause.
 *
 * That made the report impossible until `WorkPatternResolver::for()` was fixed: it cached patterns per
 * employee *per day*, so a month cost 31 queries a head and this register would have cost upwards of twelve
 * hundred. It now loads an employee's assignments once.
 *
 * **The payslip comparison is read through the query builder, not through `Payslip`.** `payroll` requires
 * `attendance`, so naming a payroll class here would close a cycle, and `ModuleBoundaryTest`'s tangled-module
 * budget is nought. Three columns of one table, guarded on the module being enabled, is the same discipline
 * the construction reports use for the same reason.
 */
class AttendanceRegister
{
    public function __construct(private readonly AttendanceCalendar $calendar) {}

    /**
     * One row per employee: a status per day, and the four totals payroll cares about.
     *
     * @return array{
     *     days: array<int, string>,
     *     employees: array<int, array{
     *         employee: Employee, statuses: array<string, string>, paid: float, lop: float,
     *         overtime: float, late: int, incomplete: int, payslip_paid: ?float,
     *     }>,
     * }
     */
    public function forMonth(int $year, int $month): array
    {
        $start = Carbon::create($year, $month, 1)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $employees = Employee::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('left_on')->orWhereDate('left_on', '>=', $start->toDateString()))
            ->with('user')
            ->orderBy('employee_id')
            ->get();

        if ($employees->isEmpty()) {
            return ['days' => [], 'employees' => []];
        }

        // Every status in the month, in one query. `keyBy` on the pair rather than a nested group so the
        // lookup below is a single array read per cell.
        $statuses = AttendanceDay::query()
            ->whereIn('employee_id', $employees->modelKeys())
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->get()
            ->keyBy(fn (AttendanceDay $day): string => $day->employee_id.'@'.$day->date->toDateString());

        $payslipPaidDays = $this->payslipPaidDays($employees, $start);

        $days = [];
        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            $days[] = $date->toDateString();
        }

        $rows = [];

        foreach ($employees as $employee) {
            $summary = $this->calendar->summarise($employee, $year, $month);

            $perDay = [];
            $late = 0;

            foreach ($days as $date) {
                $day = $statuses->get($employee->getKey().'@'.$date);
                $perDay[$date] = $day?->status ?? AttendanceDay::STATUS_NOT_MARKED;
                $late += (int) ($day?->late_minutes ?? 0);
            }

            $rows[] = [
                'employee' => $employee,
                'statuses' => $perDay,
                'paid' => $summary->paidDays(),
                'lop' => $summary->lossOfPayDays(),
                'overtime' => $summary->overtimeHours(),
                'late' => $late,
                // Days nobody marked. Counted as worked by `paidDays()`, deliberately — "a day nobody
                // recorded is not a day anybody missed" — which is exactly why the count has to be visible.
                'incomplete' => $summary->unknownDays,
                'payslip_paid' => $payslipPaidDays[$employee->getKey()] ?? null,
            ];
        }

        return ['days' => $days, 'employees' => $rows];
    }

    /**
     * Who is present, late and on leave on one day — `docs/reports-expansion-plan.md` Phase 5.2.
     *
     * **A single grouped query, not the register's per-employee walk.** `forMonth()` above builds a grid and
     * needs a row per employee per day; a dashboard wants four numbers about one day, and running the grid to
     * get them would be the per-row shape this plan's own risk list names.
     *
     * **`not_marked` is counted and reported, because it is the finding.** A day nobody recorded is not a day
     * nobody worked — the register's own note leads with unmarked days for that reason — and a dashboard
     * showing "12 present" for a company of thirty, with the other eighteen absent from every figure, would
     * read as an attendance problem rather than a recording one.
     *
     * Half days and work from home count as present: one is a shorter day and the other a different desk, and
     * neither is an absence.
     *
     * @return array{present: int, late: int, on_leave: int, unmarked: int, marked: int}
     */
    public function daySummary(?string $on = null): array
    {
        $date = Carbon::parse($on ?? now()->toDateString())->toDateString();

        $rows = AttendanceDay::query()
            ->whereDate('date', $date)
            ->selectRaw('status, count(*) as days, sum(case when late_minutes > 0 then 1 else 0 end) as late')
            ->groupBy('status')
            ->get();

        $count = fn (string ...$statuses): int => (int) $rows
            ->whereIn('status', $statuses)
            ->sum('days');

        return [
            'present' => $count(
                AttendanceDay::STATUS_PRESENT,
                AttendanceDay::STATUS_HALF_DAY,
                AttendanceDay::STATUS_WORK_FROM_HOME,
            ),
            // Lateness is a property of a day somebody attended, so it is counted from the same rows rather
            // than as a status of its own — a late arrival is present *and* late.
            'late' => (int) $rows->sum('late'),
            'on_leave' => $count(AttendanceDay::STATUS_ON_LEAVE),
            'unmarked' => $count(AttendanceDay::STATUS_NOT_MARKED),
            'marked' => (int) $rows->sum('days'),
        ];
    }

    /**
     * What each payslip says it prorated on, for this month.
     *
     * **Read as three columns of a table rather than through `Payslip`.** `payroll` requires `attendance`, so
     * an import here would be a cycle; this is the cross-boundary read the construction reports established
     * for the same reason. Through `TenantDb`, because a bare `DB::table()` builds against the landlord
     * connection and the test suite cannot catch that.
     *
     * Matched on the month *name* and the fiscal year, because that is how payslips are filed — a payslip
     * has no date, only `month` and `fiscal_year_id` (see `App\Support\PayrollMonth`). Both are needed: the
     * name alone would match the same month of every year the company has traded.
     *
     * No fiscal year covering the date means no payslip can be identified, so there is nothing to compare
     * and the report says so rather than matching the wrong year's figures.
     *
     * @param  Collection<int, Employee>  $employees
     * @return array<int, float>
     */
    private function payslipPaidDays(Collection $employees, Carbon $start): array
    {
        if (! modules()->enabled('payroll')) {
            return [];
        }

        $fiscalYear = ReportPeriod::yearFor($start);

        if ($fiscalYear === null) {
            return [];
        }

        return TenantDb::table('payslips')
            ->whereIn('employee_id', $employees->modelKeys())
            ->where('month', $start->format('F'))
            ->where('fiscal_year_id', $fiscalYear->getKey())
            ->pluck('paid_days', 'employee_id')
            ->map(fn ($days): float => (float) $days)
            ->all();
    }
}
