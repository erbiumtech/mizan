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

    /*
     * There is deliberately no impersonate() here. Gate::before in
     * AppServiceProvider returns true for an Administrator on every ability but
     * 'create', so a policy method could never refuse one — and impersonation is a
     * set of refusals (not a super admin, not deactivated, not another company's
     * user, not yourself). A policy would look like protection while granting
     * everything. App\Support\Impersonation::allows() is the authority, called
     * directly by the action and again inside start().
     */
}
