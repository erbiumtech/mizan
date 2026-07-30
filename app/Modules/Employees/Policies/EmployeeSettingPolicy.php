<?php

namespace App\Modules\Employees\Policies;

use App\Modules\Employees\Models\EmployeeSetting;
use App\Modules\Core\Models\User;

class EmployeeSettingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('EmployeeSettingView');
    }

    public function view(User $user, EmployeeSetting $employeeSetting): bool
    {
        return $user->hasPermissionTo('EmployeeSettingView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('EmployeeSettingCreate');
    }

    /**
     * Employees may edit their own settings row without the update permission:
     * the write is intercepted and becomes a pending EmployeeChangeRequest, so
     * nothing changes until an Administrator/Manager/CEO approves it.
     */
    public function update(User $user, EmployeeSetting $employeeSetting): bool
    {
        return $user->hasPermissionTo('EmployeeSettingUpdate')
            || $employeeSetting->employee?->user_id === $user->id;
    }

    public function delete(User $user, EmployeeSetting $employeeSetting): bool
    {
        return $user->hasPermissionTo('EmployeeSettingDelete');
    }
}
