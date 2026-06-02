<?php

namespace App\Policies;

use App\Models\Mpr;
use App\Models\User;
use App\Services\RoleService; // 💡 NEW DECOUPLED ARCHITECTURE: Service import ki
use Illuminate\Auth\Access\HandlesAuthorization;

class MprPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Mpr $mpr): bool
    {
        $roleService = new RoleService();

        return $roleService->isAdmin($user) || $mpr->user_id === $user->id;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        $roleService = new RoleService();

        return $roleService->isAdmin($user);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Mpr $mpr): bool
    {
        $roleService = new RoleService();
        return $roleService->isAdmin($user);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Mpr $mpr): bool
    {
        $roleService = new RoleService();
        return $roleService->isAdmin($user);
    }
}
