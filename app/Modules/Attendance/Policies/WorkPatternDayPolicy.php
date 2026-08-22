<?php

namespace App\Modules\Attendance\Policies;

use App\Modules\Attendance\Models\WorkPatternDay;
use App\Modules\Core\Models\User;

/** Days belong to their pattern and borrow its permissions. */
class WorkPatternDayPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('WorkPatternView');
    }

    public function view(User $user, WorkPatternDay $day): bool
    {
        return $user->hasPermissionTo('WorkPatternView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('WorkPatternUpdate');
    }

    public function update(User $user, WorkPatternDay $day): bool
    {
        return $user->hasPermissionTo('WorkPatternUpdate');
    }

    public function delete(User $user, WorkPatternDay $day): bool
    {
        return $user->hasPermissionTo('WorkPatternUpdate');
    }
}
