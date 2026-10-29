<?php

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Models\AttendanceDay;
use App\Modules\Employees\Events\LateMarkRecorded;
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

        // The pattern's unpaid break comes off before anything is judged — docs/saas-launch-plan.md §14.4.
        if ($worked !== null) {
            $worked = max(0, $worked - (int) ($this->patterns->for($employee, $date)?->break_minutes ?? 0));
        }

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
            $day = $existing->refresh();
        } else {
            $day = AttendanceDay::create($attributes + [
                'employee_id' => $employee->getKey(),
                'date' => $date->toDateString(),
            ]);
        }

        // Announced, not acted on — plan §8.1. Lateness is recorded here and docks
        // nothing; the merit ledger decides whether it is evidence.
        if ((int) $day->late_minutes > 0) {
            LateMarkRecorded::dispatch($employee->getKey(), $day->getKey(), $date->toDateString(), (int) $day->late_minutes);
        }

        return $day;
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

        // The pattern's own grace when it has one — docs/saas-launch-plan.md §14.4 — else the company's.
        $grace = $pattern->grace_minutes ?? (int) setting('attendance.late_grace_minutes', 15);
        $expectedStart = Carbon::parse($date->toDateString().' '.$startTime)->addMinutes((int) $grace);

        return $checkInAt->gt($expectedStart) ? $expectedStart->diffInMinutes($checkInAt) : 0;
    }

    /**
     * The employee's own punch, now — docs/saas-launch-plan.md §7.1.
     *
     * The first punch of the day is the check-in, the second the check-out, and a
     * third is refused: the regularization request is the way to correct a day,
     * and this must not become a way to overwrite one. Goes through record(), so
     * lateness and overtime are derived exactly as for an imported day.
     *
     * The position is the device's reading at that moment, kept with the day and
     * judged by nothing. A clock-out keeps the clock-in's reading unless the day
     * has none yet. Null is an honest answer: a refused or absent fix is still a
     * punch.
     *
     * @param  array{latitude: float|string, longitude: float|string, accuracy_m?: int|string|null}|null  $position
     */
    public function clock(Employee $employee, ?array $position = null, string $source = AttendanceDay::SOURCE_SELF_SERVICE): AttendanceDay
    {
        $now = Carbon::now();
        $existing = AttendanceDay::forDate($employee->getKey(), $now->copy()->startOfDay());

        if ($existing?->check_in_at && $existing->check_out_at) {
            throw new InvalidArgumentException(
                'Already clocked in and out today. To correct the times, file a regularization request.'
            );
        }

        // The fence — plan §7.9. Judged only when the employee's location has a
        // radius and the company has switched judging on; refused or flagged,
        // never silently moved. No reading at all cannot be inside a fence.
        $outside = $this->outsideGeofence($employee, $position);

        if ($outside === true && $this->geofenceMode() === 'refuse') {
            $location = $employee->location;
            $where = $position !== null && $location
                ? number_format($location->distanceTo((float) $position['latitude'], (float) $position['longitude'])).' m from '.$location->name
                : 'with no location reading';

            throw new InvalidArgumentException(
                "Clocking in needs you at {$location?->name}: this punch was made {$where}. "
                .'Clock in from the site, or file a regularization request.'
            );
        }

        $day = $existing?->check_in_at
            ? $this->record(
                $employee,
                $now,
                $existing->status === AttendanceDay::STATUS_NOT_MARKED ? AttendanceDay::STATUS_PRESENT : $existing->status,
                $existing->check_in_at->format('H:i:s'),
                $now->format('H:i:s'),
                $source,
                $existing->note,
            )
            : $this->record($employee, $now, AttendanceDay::STATUS_PRESENT, $now->format('H:i:s'), null, $source);

        if ($position !== null && $day->latitude === null) {
            $day->forceFill([
                'latitude' => (float) $position['latitude'],
                'longitude' => (float) $position['longitude'],
                'accuracy_m' => isset($position['accuracy_m']) ? (int) $position['accuracy_m'] : null,
            ])->save();
        }

        // Flag mode marks the day; a later punch inside the fence does not unmark
        // it, because the morning's reading is the one that was outside.
        if ($outside === true && $this->geofenceMode() === 'flag' && ! $day->outside_geofence) {
            $day->forceFill(['outside_geofence' => true])->save();
        }

        return $day->refresh();
    }

    public function geofenceMode(): string
    {
        $mode = (string) setting('attendance.geofence_mode', config('attendance.geofence_mode', 'off'));

        return in_array($mode, ['flag', 'refuse'], true) ? $mode : 'off';
    }

    /**
     * Outside the employee's fence? Null when nothing judges: mode off, no
     * location, or a location with no radius. True for a reading outside it, and
     * for no reading at all — a punch that cannot show where it was made is not
     * inside anything.
     *
     * @param  array{latitude: float|string, longitude: float|string}|null  $position
     */
    public function outsideGeofence(Employee $employee, ?array $position): ?bool
    {
        if ($this->geofenceMode() === 'off') {
            return null;
        }

        $location = $employee->location;

        if ($location === null || ! $location->hasGeofence()) {
            return null;
        }

        if ($position === null) {
            return true;
        }

        return ! $location->contains((float) $position['latitude'], (float) $position['longitude']);
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
