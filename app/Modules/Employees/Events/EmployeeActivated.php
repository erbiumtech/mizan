<?php

namespace App\Modules\Employees\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Somebody switched an employee on — docs/saas-launch-plan.md §14.2's bulk
 * Activate. Leave opens their year on it; Employees names nobody.
 */
class EmployeeActivated
{
    use Dispatchable;

    public function __construct(public readonly int $employeeId) {}
}
