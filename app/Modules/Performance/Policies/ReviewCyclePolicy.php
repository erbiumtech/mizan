<?php

namespace App\Modules\Performance\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Performance\Models\ReviewCycle;

/** Cycles, reviews and goals share one group: they are one feature. */
class ReviewCyclePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('ReviewView');
    }

    public function view(User $user, ReviewCycle $record): bool
    {
        return $user->hasPermissionTo('ReviewView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('ReviewCreate');
    }

    public function update(User $user, ReviewCycle $record): bool
    {
        return $user->hasPermissionTo('ReviewUpdate');
    }

    public function delete(User $user, ReviewCycle $record): bool
    {
        return $user->hasPermissionTo('ReviewDelete');
    }
}
