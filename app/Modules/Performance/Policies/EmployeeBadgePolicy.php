<?php

namespace App\Modules\Performance\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Performance\Models\EmployeeBadge;

/** Awards are made from the employee page under `BadgeAward`; nothing edits one. */
class EmployeeBadgePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('BadgeView');
    }

    public function view(User $user, EmployeeBadge $award): bool
    {
        return $user->hasPermissionTo('BadgeView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('BadgeAward');
    }

    public function update(User $user, EmployeeBadge $award): bool
    {
        return false;
    }

    public function delete(User $user, EmployeeBadge $award): bool
    {
        return false;
    }
}
