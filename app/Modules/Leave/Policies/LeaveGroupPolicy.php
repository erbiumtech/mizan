<?php

namespace App\Modules\Leave\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Leave\Models\LeaveGroup;

class LeaveGroupPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('LeaveTypeView');
    }

    public function view(User $user, LeaveGroup $row): bool
    {
        return $user->hasPermissionTo('LeaveTypeView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('LeaveTypeUpdate');
    }

    public function update(User $user, LeaveGroup $row): bool
    {
        return $user->hasPermissionTo('LeaveTypeUpdate');
    }

    public function delete(User $user, LeaveGroup $row): bool
    {
        return $user->hasPermissionTo('LeaveTypeUpdate') && ! $row->employees()->exists();
    }
}
