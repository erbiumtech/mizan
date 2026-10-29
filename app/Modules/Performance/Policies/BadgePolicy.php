<?php

namespace App\Modules\Performance\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Performance\Models\Badge;

class BadgePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('BadgeView');
    }

    public function view(User $user, Badge $badge): bool
    {
        return $user->hasPermissionTo('BadgeView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('BadgeCreate');
    }

    public function update(User $user, Badge $badge): bool
    {
        return $user->hasPermissionTo('BadgeUpdate');
    }

    public function delete(User $user, Badge $badge): bool
    {
        return $user->hasPermissionTo('BadgeDelete');
    }
}
