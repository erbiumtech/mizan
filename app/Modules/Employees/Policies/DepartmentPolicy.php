<?php

namespace App\Modules\Employees\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Department;

/** Setup rows: read by anyone who reads employees, written by whoever may edit them. */
class DepartmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('EmployeeView');
    }

    public function view(User $user, Department $row): bool
    {
        return $user->hasPermissionTo('EmployeeView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('EmployeeUpdate');
    }

    public function update(User $user, Department $row): bool
    {
        return $user->hasPermissionTo('EmployeeUpdate');
    }

    public function delete(User $user, Department $row): bool
    {
        return $user->hasPermissionTo('EmployeeUpdate') && ! $row->employees()->exists();
    }
}
