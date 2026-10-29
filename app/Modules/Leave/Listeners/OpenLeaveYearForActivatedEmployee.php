<?php

namespace App\Modules\Leave\Listeners;

use App\Modules\Employees\Events\EmployeeActivated;
use App\Modules\Employees\Models\Employee;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Services\LeaveEntitlementService;

/** The activated employee's leave year, opened — docs/saas-launch-plan.md §14.2. */
class OpenLeaveYearForActivatedEmployee
{
    public function __construct(private readonly LeaveEntitlementService $entitlements) {}

    public function handle(EmployeeActivated $event): void
    {
        if (! modules()->enabled('leave')) {
            return;
        }

        $employee = Employee::query()->find($event->employeeId);

        if ($employee === null) {
            return;
        }

        foreach (LeaveType::query()->where('is_active', true)->get() as $type) {
            $this->entitlements->open($employee, $type);
        }
    }
}
