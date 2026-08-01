<?php

namespace App\Modules\Core\Policies;

use App\Modules\Core\Models\User;
use Spatie\Permission\Models\Permission;

class PermissionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('viewAnyPermission');
    }

    public function view(User $user, Permission $permission): bool
    {
        return $user->hasPermissionTo('viewPermission');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('createPermission');
    }

    public function update(User $user, Permission $permission): bool
    {
        return $user->hasPermissionTo('updatePermission');
    }

    public function delete(User $user, Permission $permission): bool
    {
        return $user->hasPermissionTo('deletePermission');
    }
}
