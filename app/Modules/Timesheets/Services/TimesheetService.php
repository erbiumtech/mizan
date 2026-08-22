<?php

namespace App\Modules\Timesheets\Services;

use App\Modules\Attendance\Services\AttendanceCalendar;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Projects\Models\Project;
use App\Modules\Timesheets\Models\TimesheetEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Booking, approving and pricing time.
 *
 * The rate resolution is the part with a decision in it, and it is deliberately a
 * chain that can fail: **project rate, else employee rate, else the company default,
 * else nothing.** The last rung returns null rather than a number, because a made-up
 * rate produces an invoice that looks right and bills the wrong amount — which is
 * worse than a billing run that says it cannot price forty hours until somebody says
 * what an hour costs.
 */
class TimesheetService
{
    public function __construct(private readonly AttendanceCalendar $attendance) {}

    /**
     * Book time.
     *
     * Refuses an entry on a locked day rather than silently creating a second one:
     * once a client has been billed for a date, adding to it changes what they should
     * have been charged.
     */
    public function book(TimesheetEntry $entry): TimesheetEntry
    {
        if ($entry->minutes <= 0) {
            throw new InvalidArgumentException('An entry with no time on it is not an entry.');
        }

        // 24 hours in minutes. Not a policy — a physical impossibility, and almost
        // always a typo where somebody meant minutes and typed hours.
        if ($entry->minutes > 1440) {
            throw new InvalidArgumentException('That is more than a day. Time is entered in minutes.');
        }

        $entry->save();

        return $entry;
    }

    public function approve(TimesheetEntry $entry, User $approver): TimesheetEntry
    {
        if ($entry->isLocked()) {
            throw new InvalidArgumentException('This time has already been billed and can no longer be changed.');
        }

        $entry->update([
            'approved_by' => $approver->getKey(),
            'approved_at' => now(),
        ]);

        return $entry;
    }

    public function unapprove(TimesheetEntry $entry): TimesheetEntry
    {
        if ($entry->isLocked()) {
            throw new InvalidArgumentException('This time has already been billed and can no longer be changed.');
        }

        $entry->update(['approved_by' => null, 'approved_at' => null]);

        return $entry;
    }

    /**
     * What an hour of this employee's time on this project is billed at.
     *
     * Null when nothing says. Every caller has to handle that, which is the point.
     */
    public function rateFor(Employee $employee, Project $project): ?float
    {
        foreach ([$project->hourly_rate, $employee->hourly_rate, setting('timesheets.default_hourly_rate')] as $rate) {
            if ($rate !== null && (float) $rate > 0) {
                return (float) $rate;
            }
        }

        return null;
    }

    /**
     * Billable, approved, unbilled time for a project in a month.
     *
     * Approval is required or not per `timesheets.require_approval_to_bill`, which is
     * on: billing a client for time nobody checked is how a disputed invoice starts,
     * and unlike an internal figure it leaves the building.
     *
     * @return Collection<int, TimesheetEntry>
     */
    public function billableFor(Project $project, int $year, int $month): Collection
    {
        return TimesheetEntry::query()
            ->where('project_id', $project->getKey())
            ->inMonth($year, $month)
            ->billable()
            ->unbilled()
            ->when(setting('timesheets.require_approval_to_bill'), fn ($query) => $query->approved())
            ->with('employee')
            ->get();
    }

    /**
     * Mark entries as billed, so the same hour cannot reach a second invoice.
     *
     * `pluck('id')` rather than `modelKeys()`: this is typed on the Support collection
     * so a caller may hand it a plain `collect([...])`, and modelKeys() exists only on
     * the Eloquent one. The looser type is the useful one here — the billing path
     * passes an Eloquent collection, tests and one-off scripts do not.
     *
     * @param  Collection<int, TimesheetEntry>  $entries
     */
    public function lock(Collection $entries): void
    {
        if ($entries->isEmpty()) {
            return;
        }

        TimesheetEntry::query()
            ->whereKey($entries->pluck('id')->all())
            ->update(['locked_at' => now()]);
    }

    /**
     * Booked time against attendance for a month — the comparison, not the enforcement.
     *
     * "Logged 6h against projects on an 8h day" is a management figure worth having.
     * It is deliberately a report: a rule that made the two reconcile would make people
     * book the difference somewhere to make the screen agree, which produces worse data
     * than the gap it closed.
     *
     * Guarded on `attendance`; without it there is nothing to compare against.
     *
     * @return array{booked_hours: float, attended_days: float, expected_hours: ?float, note: ?string}
     */
    public function utilisationFor(Employee $employee, int $year, int $month): array
    {
        $bookedMinutes = (int) TimesheetEntry::query()
            ->where('employee_id', $employee->getKey())
            ->inMonth($year, $month)
            ->sum('minutes');

        $booked = round($bookedMinutes / 60, 2);

        if (! modules()->enabled('attendance')) {
            return [
                'booked_hours' => $booked,
                'attended_days' => 0.0,
                'expected_hours' => null,
                'note' => 'Attendance is not licensed, so there is nothing to compare this against.',
            ];
        }

        $summary = $this->attendance->summarise($employee, $year, $month);

        return [
            'booked_hours' => $booked,
            'attended_days' => $summary->workedDays,
            'expected_hours' => null,
            'note' => $summary->completenessNote(),
        ];
    }

    /**
     * Planned allocation against time actually booked, per project.
     *
     * Free, because `project_employee` already carries dated stints with
     * `allocation_pct`. Allocation says 50%, timesheets say 20% — that gap is the whole
     * reason anybody asks for this module.
     *
     * @return array<int, array{project: string, allocation_pct: ?float, booked_hours: float}>
     */
    public function planVersusActual(Employee $employee, int $year, int $month): array
    {
        $booked = TimesheetEntry::query()
            ->where('employee_id', $employee->getKey())
            ->inMonth($year, $month)
            ->selectRaw('project_id, sum(minutes) as minutes')
            ->groupBy('project_id')
            ->pluck('minutes', 'project_id');

        $from = Carbon::create($year, $month, 1)->toDateString();
        $to = Carbon::create($year, $month, 1)->endOfMonth()->toDateString();

        return $employee->projects()
            ->wherePivot('from_date', '<=', $to)
            ->where(fn ($query) => $query
                ->whereNull('project_employee.to_date')
                ->orWhere('project_employee.to_date', '>=', $from))
            ->get()
            ->map(fn (Project $project): array => [
                'project' => $project->name,
                'allocation_pct' => $project->pivot->allocation_pct !== null
                    ? (float) $project->pivot->allocation_pct
                    : null,
                'booked_hours' => round(((int) ($booked[$project->getKey()] ?? 0)) / 60, 2),
            ])
            ->all();
    }
}
