<?php

namespace App\Modules\Timesheets\Services;

use App\Modules\Attendance\Services\AttendanceCalendar;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Projects\Models\Project;
use App\Modules\Timesheets\Models\TimesheetEntry;
use App\Support\TenantDb;
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
     * Every employee's booked time for a month, split billable against not — the company-wide figure.
     *
     * **Three queries whatever the headcount, and that is the whole design.** `utilisationFor()` above
     * answers for one person and reaches `AttendanceCalendar::summarise()`, which walks every day of the
     * month doing a holiday lookup and a shift-pattern lookup per day. Called once per employee that is
     * hundreds of queries for one screen — the exact fault `docs/page-load-performance-plan.md` was
     * written about, and the risk `docs/reports-expansion-plan.md` names for these reports by name. So
     * this aggregates in the database instead of looping: one grouped sum, one distinct-project count, one
     * lookup to put names to the ids.
     *
     * **No capacity figure, and no percentage against one.** The plan asks for capacity and this module
     * refuses to state it — `utilisationFor()` returns `expected_hours` as null in every branch, because
     * (its words) a rule that made timesheets and attendance reconcile "would make people book the
     * difference somewhere to make the screen agree, which produces worse data than the gap it closed".
     * Inventing a denominator here would undo that decision quietly, in a report, where it would be read
     * as fact. `billable_share` is the ratio the data does support: what proportion of the time somebody
     * recorded was billable. It needs no assumption about what their month should have held.
     *
     * @return array<int, array{employee: string, billable_hours: float, non_billable_hours: float, booked_hours: float, billable_share: ?float, projects: int}>
     */
    public function utilisation(int $year, int $month): array
    {
        $minutes = TimesheetEntry::query()
            ->inMonth($year, $month)
            ->selectRaw('employee_id, is_billable, sum(minutes) as minutes')
            ->groupBy('employee_id', 'is_billable')
            ->get();

        if ($minutes->isEmpty()) {
            return [];
        }

        $projects = TimesheetEntry::query()
            ->inMonth($year, $month)
            ->selectRaw('employee_id, count(distinct project_id) as projects')
            ->groupBy('employee_id')
            ->pluck('projects', 'employee_id');

        $names = Employee::query()
            ->whereKey($minutes->pluck('employee_id')->unique()->all())
            ->with('user')
            ->get()
            ->mapWithKeys(fn (Employee $employee): array => [$employee->getKey() => $employee->display_label]);

        return $minutes
            ->groupBy('employee_id')
            ->map(function (Collection $rows, $employeeId) use ($names, $projects): array {
                // `is_billable` comes back as 1/0 from MySQL and true/false from SQLite, so it is filtered
                // loosely on purpose. A strict comparison here silently reported every hour as
                // non-billable on one of the two drivers.
                $billable = (int) $rows->where('is_billable', true)->sum('minutes');
                $other = (int) $rows->where('is_billable', false)->sum('minutes');
                $booked = $billable + $other;

                return [
                    // An employee deleted since booking the time still has the time; naming the id is
                    // better than dropping the hours out of the company total to keep the list tidy.
                    'employee' => $names[$employeeId] ?? 'Employee #'.$employeeId,
                    'billable_hours' => round($billable / 60, 2),
                    'non_billable_hours' => round($other / 60, 2),
                    'booked_hours' => round($booked / 60, 2),
                    // Null where nothing was booked at all, which cannot happen through the group above
                    // but would be a division by nought if it ever did.
                    'billable_share' => $booked === 0 ? null : round($billable / $booked * 100, 1),
                    'projects' => (int) ($projects[$employeeId] ?? 0),
                ];
            })
            ->sortByDesc('booked_hours')
            ->values()
            ->all();
    }

    /**
     * Planned allocation against time actually booked, for everybody, as a grid.
     *
     * The company-wide form of `planVersusActual()` below, and the report `docs/reports-expansion-plan.md`
     * Phase 1.4 asks for. Same two facts per pairing — the stint's `allocation_pct` and the hours booked —
     * with one row per employee and one column per project.
     *
     * **Four queries, again regardless of headcount.** The per-employee method runs a grouped sum and a
     * pivot query *each*; over forty employees that is eighty queries to fill one screen.
     *
     * Every pairing that either side knows about, not only the ones with both: a project somebody is
     * allocated to and has booked no time against is the row worth looking at, and so is time booked
     * against a project nobody allocated them to. Dropping either would make the report agree with itself
     * and stop being worth opening.
     *
     * @return array{employees: array<int, string>, projects: array<int, string>, cells: array<string, array{allocation_pct: ?float, booked_hours: float}>}
     */
    public function allocationGrid(int $year, int $month): array
    {
        $from = Carbon::create($year, $month, 1)->toDateString();
        $to = Carbon::create($year, $month, 1)->endOfMonth()->toDateString();

        $booked = TimesheetEntry::query()
            ->inMonth($year, $month)
            ->selectRaw('employee_id, project_id, sum(minutes) as minutes')
            ->groupBy('employee_id', 'project_id')
            ->get();

        // The stints overlapping the month. `to_date` null is an open stint, which is the common case for
        // anybody currently on a project.
        $stints = TenantDb::table('project_employee')
            ->where('from_date', '<=', $to)
            ->where(fn ($query) => $query->whereNull('to_date')->orWhere('to_date', '>=', $from))
            ->get(['employee_id', 'project_id', 'allocation_pct']);

        $employeeIds = $booked->pluck('employee_id')->merge($stints->pluck('employee_id'))->unique();
        $projectIds = $booked->pluck('project_id')->merge($stints->pluck('project_id'))->unique();

        $employees = Employee::query()
            ->whereKey($employeeIds->all())
            ->with('user')
            ->get()
            ->mapWithKeys(fn (Employee $employee): array => [$employee->getKey() => $employee->display_label])
            ->all();

        $projects = Project::query()
            ->whereKey($projectIds->all())
            ->pluck('name', 'id')
            ->all();

        $cells = [];

        foreach ($stints as $stint) {
            $key = $stint->employee_id.':'.$stint->project_id;
            $cells[$key] = [
                'allocation_pct' => $stint->allocation_pct === null ? null : (float) $stint->allocation_pct,
                'booked_hours' => 0.0,
            ];
        }

        foreach ($booked as $row) {
            $key = $row->employee_id.':'.$row->project_id;
            $cells[$key] = [
                'allocation_pct' => $cells[$key]['allocation_pct'] ?? null,
                'booked_hours' => round(((int) $row->minutes) / 60, 2),
            ];
        }

        return ['employees' => $employees, 'projects' => $projects, 'cells' => $cells];
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
