<?php

namespace App\Modules\Performance\Policies;

use App\Modules\Core\Models\User;

/** Templates are setup: read with reviews, written by whoever may change one. */
class ReviewTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('ReviewView');
    }

    public function view(User $user, $row): bool
    {
        return $user->hasPermissionTo('ReviewView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('ReviewUpdate');
    }

    public function update(User $user, $row): bool
    {
        return $user->hasPermissionTo('ReviewUpdate');
    }

    public function delete(User $user, $row): bool
    {
        return $user->hasPermissionTo('ReviewUpdate');
    }
}
