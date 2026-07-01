<?php

namespace App\Policies;

use App\Models\EmployeeSetting;
use App\Models\User;

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

    public function update(User $user, EmployeeSetting $employeeSetting): bool
    {
        return $user->hasPermissionTo('EmployeeSettingUpdate');
    }

    public function delete(User $user, EmployeeSetting $employeeSetting): bool
    {
        return $user->hasPermissionTo('EmployeeSettingDelete');
    }
}
