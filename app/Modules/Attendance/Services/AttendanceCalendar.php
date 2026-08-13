<?php

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Models\AttendanceDay;
use App\Modules\Core\Services\HolidayCalendar;
use App\Modules\Employees\Models\Employee;
use App\Modules\Leave\Models\LeaveDay;
use Illuminate\Support\Carbon;

/**
 * What a month looks like for one employee: which days were expected, and what is
 * known about each.
 *
 * This is where the three sources finally meet — the work pattern says which days are
 * expected, the holiday calendar removes the closed ones, and approved leave claims
 * the rest. Each of the three deliberately refuses to answer for the others, so this
 * is the only class that may combine them.
 *
 * **Its most important output is `unknownDays`.** Everything downstream — the
 * pro-rated payslip above all — has to be able to tell "this employee was absent for
 * three days" from "nobody filled in three days", and the second must never cost
 * anybody money.
 */
class AttendanceCalendar
{
    public function __construct(
        private readonly WorkPatternResolver $patterns,
        private readonly HolidayCalendar $holidays,
    ) {}

    /**
     * The month, summarised.
     *
     * Every figure here is derived on read from `attendance_days`, never stored. A
     * stored monthly total drifts against the rows the moment one is regularized, and
     * the payslip that read it would be wrong with nothing reporting the difference.
     */
    public function summarise(Employee $employee, int $year, int $month): AttendanceMonth
    {
        $start = Carbon::create($year, $month, 1)->startOfDay();
        $end = $start->copy()->endOfMonth();

        $rows = AttendanceDay::query()
            ->where('employee_id', $employee->getKey())
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->get()
            ->keyBy(fn (AttendanceDay $day): string => $day->date->toDateString());

        $holidays = array_flip($this->holidays->datesBetween($start, $end));

        $expected = 0;
        $worked = 0.0;
        $absent = 0.0;
        $onLeave = 0.0;
        $unknown = 0;
        $overtimeMinutes = 0;

        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            $key = $date->toDateString();
            $row = $rows->get($key);

            $overtimeMinutes += (int) ($row?->overtime_minutes ?? 0);

            $isHoliday = isset($holidays[$key]);
            $isPatternWorkingDay = $this->patterns->isWorkingDay($employee, $date);

            // A day the company was closed, or does not work, is expected of nobody.
            // It can still carry worked minutes — that is what earns a comp-off — but
            // it is not part of the month's denominator.
            if ($isHoliday || ! $isPatternWorkingDay) {
                continue;
            }

            $expected++;

            if (! $row || ! $row->isKnown()) {
                $unknown++;

                continue;
            }

            match (true) {
                $row->status === AttendanceDay::STATUS_HALF_DAY => $worked += 0.5,
                $row->isWorked() => $worked += 1,
                $row->status === AttendanceDay::STATUS_ON_LEAVE => $onLeave += 1,
                $row->status === AttendanceDay::STATUS_ABSENT => $absent += 1,
                default => null,
            };

            // A half day is half worked and half not. Which half it is depends on why,
            // and the row does not say — so it is counted as worked time and the
            // remainder is left out of `absent` rather than guessed at.
        }

        return new AttendanceMonth(
            year: $year,
            month: $month,
            expectedDays: $expected,
            workedDays: $worked,
            absentDays: $absent,
            leaveDays: $onLeave,
            unknownDays: $unknown,
            overtimeMinutes: $overtimeMinutes,
        );
    }

    /**
     * Create the month's rows for an employee, so there is something to fill in.
     *
     * Weekly offs and holidays are written with their own status rather than left
     * absent, because the difference is what a person reading the month needs to see —
     * and what phase 2a reads to know a worked Sunday was a Sunday.
     *
     * Never overwrites an existing row. A month that has been filled in, imported or
     * regularized is not something a scaffolding pass may touch.
     *
     * @return int rows created
     */
    public function scaffold(Employee $employee, int $year, int $month): int
    {
        $start = Carbon::create($year, $month, 1)->startOfDay();
        $end = $start->copy()->endOfMonth();

        $existing = AttendanceDay::query()
            ->where('employee_id', $employee->getKey())
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->pluck('date')
            ->map(fn ($date) => Carbon::parse($date)->toDateString())
            ->flip();

        $holidays = array_flip($this->holidays->datesBetween($start, $end));
        $leaveDays = $this->leaveDaysFor($employee, $start, $end);
        $created = 0;

        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            $key = $date->toDateString();

            if ($existing->has($key)) {
                continue;
            }

            $status = match (true) {
                isset($leaveDays[$key]) => AttendanceDay::STATUS_ON_LEAVE,
                isset($holidays[$key]) => AttendanceDay::STATUS_HOLIDAY,
                ! $this->patterns->isWorkingDay($employee, $date) => AttendanceDay::STATUS_WEEKLY_OFF,
                // The honest default for a working day nobody has answered for.
                default => AttendanceDay::STATUS_NOT_MARKED,
            };

            AttendanceDay::create([
                'employee_id' => $employee->getKey(),
                'date' => $key,
                'status' => $status,
                'leave_request_id' => $leaveDays[$key] ?? null,
            ]);

            $created++;
        }

        return $created;
    }

    /**
     * Dates covered by approved leave, mapped to the request that covers them.
     *
     * Guarded on `leave`: without that module there are no leave days, and an
     * attendance month simply never shows one.
     *
     * @return array<string, int>
     */
    public function leaveDaysFor(Employee $employee, Carbon $from, Carbon $to): array
    {
        if (! modules()->enabled('leave')) {
            return [];
        }

        return LeaveDay::query()
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->whereHas('request', fn ($request) => $request
                ->where('employee_id', $employee->getKey())
                ->approved())
            ->get()
            ->mapWithKeys(fn (LeaveDay $day): array => [
                $day->date->toDateString() => $day->leave_request_id,
            ])
            ->all();
    }
}
