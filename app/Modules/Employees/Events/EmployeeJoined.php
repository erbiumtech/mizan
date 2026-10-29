<?php

namespace App\Modules\Employees\Events;

use App\Modules\Employees\Models\Employee;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Somebody has just become an employee — docs/saas-launch-plan.md §7.3.
 *
 * Fired by the hire conversion once the employee row exists, so that modules
 * which have something to do for a new joiner can do it without Recruitment
 * knowing they exist: Lifecycle answers by starting the onboarding checklist.
 * Owned by Employees, because the subject is an employee and both the module
 * that fires it and the one that listens already reach Employees — the same
 * shape as UserCreated, which Core fires and Employees answers.
 */
class EmployeeJoined
{
    use Dispatchable;

    public function __construct(public readonly Employee $employee) {}
}
