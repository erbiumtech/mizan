<?php

namespace App\Modules\Leave\Reporting;

use App\Modules\Leave\Models\LeaveDay;
use App\Support\EmployeeAccess;
use App\Support\Reporting\Dataset;
use App\Support\Reporting\DatasetColumn;
use App\Support\Reporting\DatasetFilter;
use Illuminate\Database\Eloquent\Builder;

/**
 * Leave days — `docs/reports-expansion-plan.md` Phase 6, item 1.
 *
 * **The day and not the request, because a request spanning a month boundary belongs to two months.** A
 * five-day request from 29 June answers "how much leave in June" with three days and "in July" with two, and
 * only a per-day subject can say that. This is why `leave_days` exists as a table at all, and it is what makes
 * a leave report addable rather than approximate.
 *
 * **Which costs it its grouping axes, and the trade is worth stating plainly.** `leave_days` carries the date,
 * the portion and whether it is paid; the *employee* and the *type* are on the request. So this subject can
 * show them — through a declared relation — and cannot group by them, because grouping on a related column
 * needs a join the registry does not write. "Leave days per employee this year" is therefore a coded report,
 * and `LeaveLiability` and the leave register are those reports. What the builder answers here is "which days,
 * whose, and were they paid", listed.
 *
 * **Access reaches through the request**, the same shape `PayslipComponentDataset` uses and for the same
 * reason: these rows have no `employee_id`, so without a `whereHas` this subject would be a way to read
 * everybody's leave through a table that looks like an implementation detail.
 *
 * **`portion` rather than a count of rows.** A half day is stored as 0.5, so the figure that adds up is the
 * portion summed — counting rows would report a half day as a whole one, which is how a leave balance ends up
 * wrong by the number of half days somebody took.
 */
class LeaveDayDataset extends Dataset
{
    public static function label(): string
    {
        return 'Leave days';
    }

    public static function description(): string
    {
        return 'One day of leave — a whole day or a half — with whose it was and whether it was paid.';
    }

    public static function model(): string
    {
        return LeaveDay::class;
    }

    public static function permission(): string
    {
        return 'LeaveRequestView';
    }

    public static function periodColumn(): ?string
    {
        return 'date';
    }

    protected static function access(Builder $query): Builder
    {
        return $query->whereHas(
            'request',
            fn (Builder $requests) => app(EmployeeAccess::class)
                ->scopeAccessibleEmployees($requests, auth()->user(), 'employee_id'),
        );
    }

    public static function columns(): array
    {
        return [
            DatasetColumn::make('date', 'Date', DatasetColumn::DATE),
            DatasetColumn::make('portion', 'Days', DatasetColumn::NUMBER),
            DatasetColumn::make('is_paid', 'Paid', DatasetColumn::BOOLEAN),

            // Two hops each, and not groupable: the employee and the type live on the request. See the class
            // docblock for why that is the trade rather than an oversight.
            DatasetColumn::related('employee', 'Employee', 'request.employee.name'),
            DatasetColumn::related('leave_type', 'Leave type', 'request.leaveType.label'),
            DatasetColumn::related('request_status', 'Request status', 'request.status'),
            DatasetColumn::related('request_from', 'Request from', 'request.from_date', DatasetColumn::DATE),
            DatasetColumn::related('request_to', 'Request to', 'request.to_date', DatasetColumn::DATE),
        ];
    }

    public static function filters(): array
    {
        return [
            DatasetFilter::dateRange('period', 'Date', 'date'),
            DatasetFilter::flag('paid', 'Paid leave', 'is_paid'),
        ];
    }
}
