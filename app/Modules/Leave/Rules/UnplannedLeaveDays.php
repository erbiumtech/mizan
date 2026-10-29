<?php

namespace App\Modules\Leave\Rules;

use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Rules\Metric;
use App\Modules\Employees\Rules\RuleRegistry;
use App\Modules\Employees\Rules\Window;
use App\Modules\Leave\Models\LeaveRequest;

/**
 * leave.unplanned_days — docs/saas-launch-plan.md §8.9: approved leave asked for
 * on or after the day it started. Sick leave mostly, and the days a manager
 * could not plan around; the kind a badge for reliability counts against.
 */
class UnplannedLeaveDays implements Metric
{
    public function key(): string
    {
        return 'leave.unplanned_days';
    }

    public function label(): string
    {
        return 'Leave: unplanned days';
    }

    public function type(): string
    {
        return RuleRegistry::TYPE_NUMBER;
    }

    public function value(Employee $employee, Window $window): int|float
    {
        return (float) LeaveRequest::query()
            ->where('employee_id', $employee->getKey())
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->whereBetween('from_date', [$window->fromDate(), $window->toDate()])
            ->whereColumn('created_at', '>=', 'from_date')
            ->sum('days');
    }
}
