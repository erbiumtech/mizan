<?php

namespace App\Modules\Leave\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Leave\Models\LeaveEncashment;

class LeaveEncashmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('LeaveRequestView');
    }

    public function view(User $user, LeaveEncashment $row): bool
    {
        return $user->hasPermissionTo('LeaveRequestView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('LeaveRequestCreate');
    }

    public function update(User $user, LeaveEncashment $row): bool
    {
        return $user->hasPermissionTo('LeaveRequestApprove') && $row->isPending();
    }

    public function delete(User $user, LeaveEncashment $row): bool
    {
        return $user->hasPermissionTo('LeaveRequestApprove') && $row->isPending();
    }
}
