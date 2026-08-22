<?php

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Models\AttendanceDay;
use App\Modules\Employees\Models\Employee;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Writing a day — from a form, an import, or an approved correction.
 *
 * Every route in goes through here for one reason: **an attendance row must never
 * contradict approved leave.** That rule has to hold for the panel, the CSV importer
 * and the regularization approval alike, and a check written at each of the three is
 * two chances to have written it differently.
 */
class AttendanceRecorder
{
    public function __construct(
        private readonly WorkPatternResolver $patterns,
        private readonly AttendanceCalendar $calendar,
    ) {}

    /**
     * Record one day, computing what can be computed from the times given.
     *
     * Idempotent on (employee, date) by the unique key, so re-importing a month
     * updates it rather than doubling it.
     */
    public function record(
        Employee $employee,
        string|Carbon $date,
        string $status,
        ?string $checkIn = null,
        ?string $checkOut = null,
        string $source = AttendanceDay::SOURCE_MANUAL,
        ?string $note = null,
    ): AttendanceDay {
        $date = Carbon::parse($date)->startOfDay();

        $this->assertDoesNotContradictLeave($employee, $date, $status);

        $existing = AttendanceDay::forDate($employee->getKey(), $date);

        $checkInAt = $checkIn ? Carbon::parse($date->toDateString().' '.$checkIn) : null;
        $checkOutAt = $checkOut ? Carbon::parse($date->toDateString().' '.$checkOut) : null;

        // A shift ending after midnight is the ordinary case on a factory floor, and
        // reading it as a negative day is the classic version of this bug.
        if ($checkInAt && $checkOutAt && $checkOutAt->lt($checkInAt)) {
            $checkOutAt->addDay();
        }

        $worked = $checkInAt && $checkOutAt ? $checkInAt->diffInMinutes($checkOutAt) : null;

        $attributes = [
            'status' => $status,
            'check_in_at' => $checkInAt,
            'check_out_at' => $checkOutAt,
            'source' => $source,
            'note' => $note,
        ];

        // Only overwrite the derived figures when times were actually given. An
        // importer that carries statuses but no clock times must not zero the minutes
        // a device recorded last week.
        if ($worked !== null) {
            $attributes['worked_minutes'] = $worked;
            $attributes['overtime_minutes'] = $this->overtimeFor($employee, $date, $worked, $status);
            $attributes['late_minutes'] = $this->latenessFor($employee, $date, $checkInAt);
        }

        if ($existing) {
            $existing->update($attributes);

            return $existing->refresh();
        }

        return AttendanceDay::create($attributes + [
            'employee_id' => $employee->getKey(),
            'date' => $date->toDateString(),
        ]);
    }

    /**
     * Overtime: minutes beyond the day's expected hours.
     *
     * On a weekly off or a holiday, **every** worked minute is overtime — there were no
     * expected hours to exceed. That is also the day that earns a compensatory off, and
     * both are true at once: the plan pays overtime for the time and credits a day in
     * lieu for the disruption, and a company that wants only one of the two switches the
     * other off.
     */
    private function overtimeFor(Employee $employee, Carbon $date, int $workedMinutes, string $status): int
    {
        if (in_array($status, AttendanceDay::NON_WORKING_STATUSES, true)) {
            return $workedMinutes;
        }

        $expected = $this->patterns->expectedHours($employee, $date);

        if ($expected === null) {
            return 0;
        }

        return max(0, $workedMinutes - (int) round($expected * 60));
    }

    /**
     * Lateness against the pattern's start time, past a grace period.
     *
     * Recorded only. Nothing in this application docks pay for lateness, and nothing
     * should without somebody asking — a system that turns a fifteen-minute traffic jam
     * into a deduction is one people learn to defeat rather than obey.
     */
    private function latenessFor(Employee $employee, Carbon $date, ?Carbon $checkInAt): int
    {
        if (! $checkInAt) {
            return 0;
        }

        $pattern = $this->patterns->for($employee, $date);
        $startTime = $pattern?->days->firstWhere('weekday', $date->dayOfWeekIso)?->start_time;

        if (! $startTime) {
            return 0;
        }

        $expectedStart = Carbon::parse($date->toDateString().' '.$startTime)
            ->addMinutes((int) setting('attendance.late_grace_minutes', 15));

        return $checkInAt->gt($expectedStart) ? $expectedStart->diffInMinutes($checkInAt) : 0;
    }

    /**
     * Run every check record() would run, and write nothing.
     *
     * What makes the importer's dry run a real dry run. Without it the preview would
     * have to either write (and not be a preview) or skip the checks (and promise a
     * clean import that then fails half way through, having already written the first
     * half — the worst of both).
     */
    public function validate(Employee $employee, string|Carbon $date, string $status): void
    {
        $this->assertDoesNotContradictLeave($employee, Carbon::parse($date)->startOfDay(), $status);
    }

    /**
     * A day covered by approved leave cannot be marked present.
     *
     * Refused rather than overwritten. The two modules disagreeing about whether
     * somebody was at work is the failure `attendance_days.leave_request_id` exists to
     * make impossible, and an importer that silently won would make the leave register
     * wrong without touching it.
     */
    private function assertDoesNotContradictLeave(Employee $employee, Carbon $date, string $status): void
    {
        if (in_array($status, [AttendanceDay::STATUS_ON_LEAVE, AttendanceDay::STATUS_NOT_MARKED], true)) {
            return;
        }

        $leaveDays = $this->calendar->leaveDaysFor($employee, $date, $date);

        if (isset($leaveDays[$date->toDateString()])) {
            throw new InvalidArgumentException(
                "{$date->format('d M Y')} is covered by approved leave, so it cannot be marked {$status}. "
                .'Withdraw the leave first if the employee actually worked.'
            );
        }
    }
}
