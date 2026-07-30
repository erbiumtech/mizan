<?php

namespace App\Modules\Core\Policies;

use App\Modules\Core\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class UserPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user)
    {
        return $user->hasPermissionTo('UserView');
    }

    public function view(User $user, User $model)
    {
        return $user->hasPermissionTo('UserView');
    }

    public function create(User $user)
    {
        return $user->hasPermissionTo('UserCreate');
    }

    public function update(User $user, User $model)
    {
        return $user->hasPermissionTo('UserUpdate');
    }

    public function delete(User $user, User $model)
    {
        return $user->hasPermissionTo('UserDelete');
    }
}
