<?php

namespace App\Modules\Attendance\Policies;

use App\Modules\Attendance\Models\EmployeeWorkPattern;
use App\Modules\Core\Models\User;

/**
 * Assignments are dated history, so they are added and ended rather than edited —
 * the same shape as employee_job_history. Deleting one rewrites which days were
 * working days in a month that has already been paid.
 */
class EmployeeWorkPatternPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('WorkPatternView');
    }

    public function view(User $user, EmployeeWorkPattern $assignment): bool
    {
        return $user->hasPermissionTo('WorkPatternView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('WorkPatternUpdate');
    }

    public function update(User $user, EmployeeWorkPattern $assignment): bool
    {
        return $user->hasPermissionTo('WorkPatternUpdate');
    }

    public function delete(User $user, EmployeeWorkPattern $assignment): bool
    {
        return $user->hasPermissionTo('WorkPatternDelete');
    }
}
