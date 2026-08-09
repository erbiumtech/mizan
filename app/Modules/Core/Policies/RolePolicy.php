<?php

namespace App\Modules\Core\Policies;

use App\Modules\Core\Models\User;
use Spatie\Permission\Models\Role;

class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('viewAnyRole');
    }

    public function view(User $user, Role $role): bool
    {
        return $user->hasPermissionTo('viewRole');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('createRole');
    }

    public function update(User $user, Role $role): bool
    {
        return $user->hasPermissionTo('updateRole');
    }

    public function delete(User $user, Role $role): bool
    {
        return $user->hasPermissionTo('deleteRole');
    }
}
