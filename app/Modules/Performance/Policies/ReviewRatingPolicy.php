<?php

namespace App\Modules\Performance\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Performance\Models\ReviewRating;

/** A rating is part of its review and follows the review's policy. */
class ReviewRatingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('ReviewView');
    }

    public function view(User $user, ReviewRating $rating): bool
    {
        return $user->can('view', $rating->review);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('ReviewUpdate');
    }

    public function update(User $user, ReviewRating $rating): bool
    {
        return $user->can('update', $rating->review);
    }

    public function delete(User $user, ReviewRating $rating): bool
    {
        return false;
    }
}
