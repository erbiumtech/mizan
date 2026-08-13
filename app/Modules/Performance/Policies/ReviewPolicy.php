<?php

namespace App\Modules\Performance\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Performance\Models\Review;

/** Cycles, reviews and goals share one group: they are one feature. */
class ReviewPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('ReviewView');
    }

    public function view(User $user, Review $record): bool
    {
        return $user->hasPermissionTo('ReviewView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('ReviewCreate');
    }

    public function update(User $user, Review $record): bool
    {
        return $user->hasPermissionTo('ReviewUpdate');
    }

    public function delete(User $user, Review $record): bool
    {
        return $user->hasPermissionTo('ReviewDelete');
    }
}
