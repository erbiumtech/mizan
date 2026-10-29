<?php

namespace App\Modules\Employees\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Attendance recorded somebody arriving late — docs/saas-launch-plan.md §8.1.
 *
 * Announced, not acted on: Attendance records lateness and docks nothing, and
 * the merit ledger decides whether a late mark is evidence (a setting, zero as
 * shipped). Owned by Employees for the reason EmployeeJoined is: both modules
 * already reach it and neither may reach the other.
 */
class LateMarkRecorded
{
    use Dispatchable;

    public function __construct(
        public readonly int $employeeId,
        public readonly int $attendanceDayId,
        public readonly string $date,
        public readonly int $lateMinutes,
    ) {}
}
