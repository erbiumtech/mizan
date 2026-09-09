<?php

namespace App\Modules\Payroll\Services;

use App\Modules\Attendance\Services\AttendanceCalendar;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\Core\Services\HolidayCalendar;
use App\Modules\Employees\Models\Employee;
use App\Modules\Leave\Models\LeaveDay;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\Payslip;
use App\Support\Contracts\WorkingDayCalendar;
use App\Support\PayrollMonth;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The four attendance columns on a payslip, worked out from leave and attendance.
 *
 * These columns spent their whole life meaning whatever the person filling them in
 * assumed. docs/hrms-plan.md §11 settled the convention, and this class is where it is
 * now enforced rather than described:
 *
 *  | column              | means                                        | effect on pay |
 *  |---------------------|----------------------------------------------|---------------|
 *  | `leaves_taken`      | approved **paid** leave days consumed         | none          |
 *  | `lop_days`          | **unpaid** absence: loss of pay               | the only one pro-rating may read |
 *  | `paid_days`         | `total_working_days − lop_days`               | the numerator |
 *  | `total_working_days`| working days the month had                    | the divisor's basis |
 *
 * Both sources are guarded. Without `leave`, `leaves_taken` stays 0 and unpaid leave
 * contributes nothing. Without `attendance` there is no work pattern, so the month is
 * measured from the calendar instead: every day that is not a company holiday and not
 * one of the configured weekend days (`leave.weekend_days`, the same answer Leave gets
 * through WorkingDayCalendar). That used to be left at 0 — "not known" — which printed
 * three zeros on every payslip of a company running payroll without attendance, and
 * the zeros were read as a broken payslip rather than as a modest statement.
 *
 * Nothing about pay changes by itself: pro-rating still needs
 * `payroll.prorate_on_attendance`, which is off.
 */
class AttendanceFigures
{
    public function __construct(
        private readonly AttendanceCalendar $calendar,
        private readonly WorkingDayCalendar $workingDays,
        private readonly HolidayCalendar $holidays,
    ) {}

    /**
     * The four figures for one employee in one payroll month.
     *
     * @return array{total_working_days: float, paid_days: float, lop_days: float, leaves_taken: float}
     */
    public function for(Employee $employee, string $month, FiscalYear $fiscalYear): array
    {
        $firstDay = PayrollMonth::firstDay($month, $fiscalYear);
        $lastDay = $firstDay->copy()->endOfMonth();

        $days = $this->countableLeaveDays($employee, $month, $fiscalYear);

        // Summed on `portion`, so a half day costs half a day. Split by `is_paid` as it
        // was recorded on the day, not as the type reads today — a type corrected later
        // must not restate a settled month.
        //
        // This is also the whole of docs/hrms-plan.md §11's convention, enforced rather
        // than described: `leaves_taken` is approved PAID leave and costs nothing, and
        // `lop_days` is unpaid absence — the only column pro-rating may ever read.
        $paidLeave = (float) $days->where('is_paid', true)->sum('portion');
        $unpaidLeave = (float) $days->where('is_paid', false)->sum('portion');

        if (! modules()->enabled('attendance')) {
            // No work pattern to ask, so the calendar answers: the days in the month
            // that are neither a company holiday nor a configured weekend day. Unpaid
            // leave is the only loss of pay a company without attendance can record.
            $expected = $this->calendarWorkingDays($employee, $firstDay, $lastDay);

            return [
                'total_working_days' => (float) $expected,
                'paid_days' => max(0.0, $expected - $unpaidLeave),
                'lop_days' => $unpaidLeave,
                'leaves_taken' => $paidLeave,
            ];
        }

        $summary = $this->calendar->summarise($employee, $firstDay->year, $firstDay->month);

        // Unpaid leave and recorded absence are both loss of pay, and they are
        // different rows: an unpaid leave day is `on_leave` in attendance and is
        // therefore NOT counted in absentDays, so adding them cannot double-count.
        $lop = $summary->lossOfPayDays() + $unpaidLeave;

        return [
            'total_working_days' => (float) $summary->expectedDays,
            'paid_days' => max(0.0, $summary->expectedDays - $lop),
            'lop_days' => $lop,
            'leaves_taken' => $paidLeave,
        ];
    }

    /**
     * Working days between two dates by the calendar alone: not a holiday, not a weekend day.
     *
     * Through WorkingDayCalendar rather than reading `leave.weekend_days` here, so the
     * payslip and the leave-day generator can never disagree about which days a week has.
     */
    private function calendarWorkingDays(Employee $employee, CarbonInterface $from, CarbonInterface $to): int
    {
        $days = 0;

        for ($date = $from->copy(); $date->lte($to); $date->addDay()) {
            if (! $this->holidays->isHoliday($date) && $this->workingDays->isWorkingDay($employee->getKey(), $date)) {
                $days++;
            }
        }

        return $days;
    }

    /**
     * The leave days this month's payslip should count.
     *
     * **Two sets, and the second is the point of §10.17.**
     *
     *  1. Approved, unsettled days falling inside this month.
     *  2. Approved, unsettled days from EARLIER months whose payroll run is locked.
     *
     * The second set is what "leave approved after sign-off adjusts the next month" means.
     * Without it there are only two behaviours available and both are wrong: count the day
     * in its own month, where the payslip is locked and the figure is simply lost; or
     * count it in the current month every time figures are pulled, so it is counted again
     * every month for ever.
     *
     * Days from an earlier OPEN month are deliberately excluded — that month's payslip can
     * still pick them up itself, and pulling them forward would take them off a payslip
     * somebody is about to finish.
     *
     * @return Collection<int, LeaveDay>
     */
    public function countableLeaveDays(Employee $employee, string $month, FiscalYear $fiscalYear): Collection
    {
        if (! modules()->enabled('leave')) {
            return new Collection;
        }

        $firstDay = PayrollMonth::firstDay($month, $fiscalYear);
        $lastDay = $firstDay->copy()->endOfMonth();

        return LeaveDay::query()
            ->unsettled()
            ->whereHas('request', fn ($request) => $request
                ->where('employee_id', $employee->getKey())
                ->approved())
            ->where(fn ($query) => $query
                // This month.
                ->whereBetween('date', [$firstDay->toDateString(), $lastDay->toDateString()])
                // Or an earlier month that has been signed off and can no longer take it.
                ->orWhere(fn ($earlier) => $earlier
                    ->whereDate('date', '<', $firstDay->toDateString())
                    ->whereIn('date', $this->datesInClosedMonths($firstDay))))
            ->get();
    }

    /**
     * Mark the days a payslip counted, so no month counts them again.
     *
     * Separate from `for()` on purpose: reading the figures happens on every form render
     * and every preview, and a read that silently claimed leave days would burn them for
     * whoever looked. Only the caller that actually writes a payslip settles anything.
     *
     * @return int days settled
     */
    public function settle(Employee $employee, string $month, FiscalYear $fiscalYear, Payslip $payslip): int
    {
        $days = $this->countableLeaveDays($employee, $month, $fiscalYear);

        if ($days->isEmpty()) {
            return 0;
        }

        LeaveDay::query()
            ->whereKey($days->pluck('id')->all())
            ->update(['settled_payslip_id' => $payslip->getKey()]);

        return $days->count();
    }

    /**
     * The dates, before this month, that belong to a locked payroll run.
     *
     * Returned as dates rather than as a month list because the query above filters
     * `leave_days.date`, and a leave day knows its date rather than its payroll month.
     * Bounded to the twelve months before this one: leave approved for a month more than a
     * year settled is a data question, not a payroll adjustment, and sweeping the whole
     * history forward would put it on somebody's next payslip without warning.
     *
     * @return array<int, string>
     */
    private function datesInClosedMonths(CarbonInterface $before): array
    {
        if (! modules()->enabled('payroll')) {
            return [];
        }

        $dates = [];

        for ($offset = 1; $offset <= 12; $offset++) {
            $candidate = $before->copy()->subMonths($offset);

            $locked = PayrollRun::query()
                ->locked()
                ->where('month', $candidate->format('F'))
                ->whereHas('fiscalYear', fn ($fiscalYear) => $fiscalYear
                    ->whereDate('start_date', '<=', $candidate->toDateString())
                    ->whereDate('end_date', '>=', $candidate->toDateString()))
                ->exists();

            if (! $locked) {
                continue;
            }

            for ($day = $candidate->copy()->startOfMonth(); $day->lte($candidate->copy()->endOfMonth()); $day->addDay()) {
                $dates[] = $day->toDateString();
            }
        }

        return $dates;
    }

    /**
     * Whether the month is complete enough to reduce anybody's pay.
     *
     * False while any day is unmarked. A partly-filled month pro-rated is arithmetic
     * indistinguishable from a month where those days were genuinely worked, and the
     * employee is the one who loses — so pro-rating declines rather than guesses.
     */
    public function monthIsComplete(Employee $employee, string $month, FiscalYear $fiscalYear): bool
    {
        if (! modules()->enabled('attendance')) {
            return false;
        }

        $firstDay = PayrollMonth::firstDay($month, $fiscalYear);

        return $this->calendar->summarise($employee, $firstDay->year, $firstDay->month)->isComplete();
    }

    /** Overtime minutes recorded in the month, for phase 3a. Zero without attendance. */
    public function overtimeMinutes(Employee $employee, string $month, FiscalYear $fiscalYear): int
    {
        if (! modules()->enabled('attendance')) {
            return 0;
        }

        $firstDay = PayrollMonth::firstDay($month, $fiscalYear);

        return $this->calendar->summarise($employee, $firstDay->year, $firstDay->month)->overtimeMinutes;
    }
}
