<?php

namespace App\Modules\Employees\Reporting;

use App\Modules\Employees\Models\Employee;
use App\Support\EmployeeAccess;
use App\Support\Reporting\Dataset;
use App\Support\Reporting\DatasetColumn;
use App\Support\Reporting\DatasetFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Employees — `docs/reports-expansion-plan.md` Phase 6, item 1.
 *
 * **The subject with no period, and the clearest case for item 5's "or".** An employee is a *state*, not an
 * event. A mandatory period would have to bound `date_of_joining`, so asking for a headcount would answer
 * "who joined this quarter" — a report that is wrong in a way nobody notices, because it returns rows.
 * Joining and leaving are both filterable dates for the reports that do want them.
 *
 * **Active is a filter and not a base condition.** `HeadcountReports` had to learn this: a leavers report is
 * a report over inactive people, and a subject that quietly excluded them would make it unbuildable. So the
 * default is everybody and a report says which it wants.
 *
 * **Access is the hierarchy, on `id`.** The same `EmployeeAccess` call every employee resource makes, and the
 * reason this subject is safe to offer widely: a team lead building a report over employees gets their own
 * team, which is the report they were going to ask somebody for anyway.
 *
 * **`length_of_service` is derived and therefore cannot be summed, which is correct.** An average tenure is a
 * real question and a sum of tenures is not; being derived it offers `count` and nothing else, so the builder
 * cannot put a meaningless total under it. The coded headcount report is where average tenure belongs.
 */
class EmployeeDataset extends Dataset
{
    public static function label(): string
    {
        return 'Employees';
    }

    public static function description(): string
    {
        return 'One person on the payroll, active or not: their job, their manager and their dates.';
    }

    public static function model(): string
    {
        return Employee::class;
    }

    public static function permission(): string
    {
        return 'EmployeeView';
    }

    /** No period: an employee is a state rather than an event — see the class docblock. */
    public static function periodColumn(): ?string
    {
        return null;
    }

    protected static function access(Builder $query): Builder
    {
        return app(EmployeeAccess::class)->scopeAccessibleEmployees($query, auth()->user());
    }

    public static function columns(): array
    {
        return [
            DatasetColumn::make('name', 'Name', groupable: false),
            DatasetColumn::make('employee_id', 'Staff number', groupable: false),
            DatasetColumn::make('designation', 'Designation'),
            DatasetColumn::make('department', 'Department'),
            DatasetColumn::make('employment_type', 'Employment type'),
            DatasetColumn::make('is_active', 'Active', DatasetColumn::BOOLEAN),
            DatasetColumn::make('gender', 'Gender'),

            DatasetColumn::make('date_of_joining', 'Joined', DatasetColumn::DATE),
            DatasetColumn::make('left_on', 'Left', DatasetColumn::DATE),
            DatasetColumn::make('hourly_rate', 'Hourly rate', DatasetColumn::MONEY),

            DatasetColumn::related('manager', 'Manager', 'manager.name', groupBy: 'manager_id'),

            /*
             * Years of service, to one decimal.
             *
             * To today for somebody still here and to their leaving date for somebody who has gone — which is
             * the same rule `HeadcountReports` applies, and the reason this is not just "now minus joined": a
             * leaver's tenure stopped when they left, and letting it keep growing would make the longest-serving
             * people the ones who resigned first.
             */
            DatasetColumn::derived(
                'length_of_service',
                'Years of service',
                function (Employee $employee): ?float {
                    if ($employee->date_of_joining === null) {
                        return null;
                    }

                    $to = $employee->left_on === null
                        ? Carbon::now()
                        : Carbon::parse($employee->left_on);

                    return round(Carbon::parse($employee->date_of_joining)->floatDiffInYears($to), 1);
                },
                DatasetColumn::NUMBER,
            ),
        ];
    }

    public static function filters(): array
    {
        return [
            DatasetFilter::dateRange('joined', 'Joined between', 'date_of_joining'),
            DatasetFilter::dateRange('left', 'Left between', 'left_on'),
            DatasetFilter::flag('active', 'Currently employed', 'is_active'),
            DatasetFilter::select(
                'department',
                'Department',
                'department',
                // The departments this company actually uses, off the rows: the column is free text, so a
                // fixed list would offer filters that match nothing.
                fn (): array => static::query()
                    ->whereNotNull('department')
                    ->distinct()
                    ->orderBy('department')
                    ->pluck('department', 'department')
                    ->all(),
            ),
            DatasetFilter::search('find', 'Name or staff number contains', ['name', 'employee_id']),
        ];
    }
}
