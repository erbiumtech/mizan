<?php

namespace App\Modules\Devices\Services;

use App\Modules\Attendance\Models\AttendanceDay;
use App\Modules\Attendance\Services\AttendanceRecorder;
use App\Modules\Devices\Models\DevicePunch;
use App\Modules\Employees\Models\Employee;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Punches into attendance days — docs/saas-launch-plan.md §8.7.
 *
 * Per PIN per date, the first punch is the check-in and the last the check-out,
 * handed to AttendanceRecorder with source = device: the path the CSV import
 * walks, idempotent on (employee, date), so a device that re-sends a day changes
 * nothing. A PIN no employee carries is left unfolded and reported, which is how
 * a new hire's enrolment is noticed. Today's punches are folded too — a second
 * run later in the day extends the check-out — so unfolded means "never seen by
 * the folder", not "the day is over".
 */
class DevicePunchFolder
{
    public function __construct(private readonly AttendanceRecorder $recorder) {}

    /** @return array{folded: int, days: int, unmapped: array<string, int>, errors: array<int, string>} */
    public function fold(): array
    {
        $employees = Employee::query()->whereNotNull('device_pin')->get()->keyBy(fn (Employee $e): string => ltrim(trim((string) $e->device_pin), '0') ?: '0');

        $pending = DevicePunch::query()->unfolded()->orderBy('punched_at')->get();
        $folded = 0;
        $days = 0;
        $unmapped = [];
        $errors = [];

        foreach ($pending->groupBy('device_pin') as $pin => $punches) {
            $employee = $employees->get((string) $pin);

            if ($employee === null) {
                $unmapped[(string) $pin] = $punches->count();

                continue;
            }

            foreach ($punches->groupBy(fn (DevicePunch $p): string => $p->punched_at->toDateString()) as $date => $ofDay) {
                // The day's whole record, not only the unfolded part: a check-out punch
                // arriving after the morning was folded must still see the morning.
                $all = DevicePunch::query()
                    ->where('device_pin', $pin)
                    ->whereBetween('punched_at', [$date.' 00:00:00', $date.' 23:59:59'])
                    ->orderBy('punched_at')
                    ->get();

                try {
                    $this->recorder->record(
                        employee: $employee,
                        date: $date,
                        status: AttendanceDay::STATUS_PRESENT,
                        checkIn: $all->first()->punched_at->format('H:i:s'),
                        checkOut: $all->count() > 1 ? $all->last()->punched_at->format('H:i:s') : null,
                        source: AttendanceDay::SOURCE_DEVICE,
                        note: $all->count().' punch'.($all->count() === 1 ? '' : 'es').' from the device',
                    );
                } catch (Throwable $e) {
                    // The leave contradiction the recorder refuses, most often. Left unfolded
                    // and named, so the next run tries again once somebody has looked.
                    $errors[] = "PIN {$pin} on {$date}: {$e->getMessage()}";

                    continue;
                }

                DevicePunch::query()->whereIn('id', $ofDay->pluck('id'))->update(['folded_at' => now()]);
                $folded += $ofDay->count();
                $days++;
            }
        }

        return ['folded' => $folded, 'days' => $days, 'unmapped' => $unmapped, 'errors' => $errors];
    }

    /** PINs punching with nobody to belong to, with how many punches each — the settings section's list. */
    public function unmapped(): Collection
    {
        $known = Employee::query()->whereNotNull('device_pin')->pluck('device_pin')->map(fn ($p): string => ltrim(trim((string) $p), '0') ?: '0');

        return DevicePunch::query()
            ->unfolded()
            ->selectRaw('device_pin, count(*) as punches, max(punched_at) as last_punch')
            ->groupBy('device_pin')
            ->get()
            ->reject(fn ($row): bool => $known->contains((string) $row->device_pin))
            ->values();
    }
}
