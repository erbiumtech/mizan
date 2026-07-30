<?php

namespace App\Modules\Mpr\Policies;

use App\Models\Mpr;
use App\Models\User;

class MprPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('MPRView');
    }

    public function view(User $user, Mpr $mpr): bool
    {
        if (! $user->hasPermissionTo('MPRView')) {
            return false;
        }

        if ($user->hasRole('Administrator')) {
            return true;
        }

        return $mpr->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('MPRCreate');
    }

    public function update(User $user, Mpr $mpr): bool
    {
        return $user->hasPermissionTo('MPRUpdate');
    }

    public function delete(User $user, Mpr $mpr): bool
    {
        return $user->hasPermissionTo('MPRDelete');
    }
}
