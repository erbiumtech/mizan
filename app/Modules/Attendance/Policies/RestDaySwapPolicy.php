<?php

namespace App\Modules\Attendance\Policies;

use App\Modules\Attendance\Models\RestDaySwap;
use App\Modules\Core\Models\User;

/** A swap changes somebody's working week, so it is the work-pattern permission that writes it. */
class RestDaySwapPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('WorkPatternView');
    }

    public function view(User $user, RestDaySwap $swap): bool
    {
        return $user->hasPermissionTo('WorkPatternView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('WorkPatternUpdate');
    }

    public function update(User $user, RestDaySwap $swap): bool
    {
        return $user->hasPermissionTo('WorkPatternUpdate');
    }

    public function delete(User $user, RestDaySwap $swap): bool
    {
        return $user->hasPermissionTo('WorkPatternUpdate');
    }
}
