<?php

namespace App\Modules\Core\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Core\Models\WorkLocation;

class WorkLocationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('WorkLocationView');
    }

    public function view(User $user, WorkLocation $location): bool
    {
        return $user->hasPermissionTo('WorkLocationView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('WorkLocationCreate');
    }

    public function update(User $user, WorkLocation $location): bool
    {
        return $user->hasPermissionTo('WorkLocationUpdate');
    }

    public function delete(User $user, WorkLocation $location): bool
    {
        return $user->hasPermissionTo('WorkLocationDelete');
    }
}
