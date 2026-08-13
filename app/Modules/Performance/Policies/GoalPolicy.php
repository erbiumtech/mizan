<?php

namespace App\Modules\Performance\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Performance\Models\Goal;

/** Cycles, reviews and goals share one group: they are one feature. */
class GoalPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('ReviewView');
    }

    public function view(User $user, Goal $record): bool
    {
        return $user->hasPermissionTo('ReviewView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('ReviewCreate');
    }

    public function update(User $user, Goal $record): bool
    {
        return $user->hasPermissionTo('ReviewUpdate');
    }

    public function delete(User $user, Goal $record): bool
    {
        return $user->hasPermissionTo('ReviewDelete');
    }
}
