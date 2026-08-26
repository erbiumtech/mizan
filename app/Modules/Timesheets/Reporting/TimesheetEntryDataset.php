<?php

namespace App\Modules\Timesheets\Reporting;

use App\Modules\Projects\Models\Project;
use App\Modules\Timesheets\Models\TimesheetEntry;
use App\Support\EmployeeAccess;
use App\Support\Reporting\Dataset;
use App\Support\Reporting\DatasetColumn;
use App\Support\Reporting\DatasetFilter;
use Illuminate\Database\Eloquent\Builder;

/**
 * Timesheet entries — `docs/reports-expansion-plan.md` Phase 6, item 1.
 *
 * **The subject with the highest row count in the application, and the one the row cap was written for.** A
 * company of forty people logging time daily is about ten thousand rows a year, and every one of them has a
 * date — so unlike payslips, this subject can and does carry a mandatory period, which is the cheaper half of
 * item 5's guard.
 *
 * **Minutes are stored and hours are derived, which is the right way round and worth stating.** The column is
 * `minutes` because that is what a person enters and because dividing on write loses precision permanently;
 * hours are what a report reads. So both are offered: `minutes` sums in SQL, `hours` is a display of the same
 * figure, and a report that totals a column gets the exact one.
 *
 * **Approval is a column, not a base condition.** `TimesheetReports::unbilledWip()` excludes unapproved time
 * when `timesheets.require_approval_to_bill` is set, because billing is a claim about money; a *report* over
 * time should be able to show what has not been approved, since that is usually why somebody is looking. So
 * `approved_at` is a column and a filter and nothing is hidden.
 *
 * **Access is the hierarchy**, the same call `TimesheetEntryResource` makes, so a manager's report is their
 * team's time.
 */
class TimesheetEntryDataset extends Dataset
{
    public static function label(): string
    {
        return 'Timesheet entries';
    }

    public static function description(): string
    {
        return 'One person\'s time on one project on one day, billable or not.';
    }

    public static function model(): string
    {
        return TimesheetEntry::class;
    }

    public static function permission(): string
    {
        return 'TimesheetView';
    }

    public static function periodColumn(): ?string
    {
        return 'date';
    }

    protected static function access(Builder $query): Builder
    {
        return app(EmployeeAccess::class)->scopeAccessibleEmployees($query, auth()->user(), 'employee_id');
    }

    public static function columns(): array
    {
        return [
            DatasetColumn::make('date', 'Date', DatasetColumn::DATE),
            DatasetColumn::related('employee', 'Employee', 'employee.name', groupBy: 'employee_id'),
            DatasetColumn::related('project', 'Project', 'project.name', groupBy: 'project_id'),
            DatasetColumn::related('project_code', 'Project code', 'project.code', groupBy: 'project_id'),

            DatasetColumn::make('minutes', 'Minutes', DatasetColumn::NUMBER),

            // The same figure a person reads. Derived, so it cannot be summed — sum the minutes and the
            // report is exact; a column of rounded hours added up is a column that disagrees with itself.
            DatasetColumn::derived(
                'hours',
                'Hours',
                fn (TimesheetEntry $entry): float => round($entry->minutes / 60, 2),
                DatasetColumn::NUMBER,
            ),

            DatasetColumn::make('is_billable', 'Billable', DatasetColumn::BOOLEAN),
            DatasetColumn::make('task', 'Task'),
            DatasetColumn::make('description', 'Description', groupable: false),
            DatasetColumn::make('approved_at', 'Approved', DatasetColumn::DATE, groupable: false),
            DatasetColumn::related('approver', 'Approved by', 'approver.name', groupBy: 'approved_by'),
        ];
    }

    public static function filters(): array
    {
        return [
            DatasetFilter::dateRange('period', 'Date', 'date'),
            DatasetFilter::select(
                'project',
                'Project',
                'project_id',
                fn (): array => Project::query()->orderBy('name')->pluck('name', 'id')->all(),
            ),
            DatasetFilter::flag('billable', 'Billable', 'is_billable'),
            DatasetFilter::search('task', 'Task or description contains', ['task', 'description']),
        ];
    }
}
