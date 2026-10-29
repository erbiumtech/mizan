<?php

namespace App\Modules\Employees\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Designation;

/** Setup rows: read by anyone who reads employees, written by whoever may edit them. */
class DesignationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('EmployeeView');
    }

    public function view(User $user, Designation $row): bool
    {
        return $user->hasPermissionTo('EmployeeView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('EmployeeUpdate');
    }

    public function update(User $user, Designation $row): bool
    {
        return $user->hasPermissionTo('EmployeeUpdate');
    }

    public function delete(User $user, Designation $row): bool
    {
        return $user->hasPermissionTo('EmployeeUpdate') && ! $row->employees()->exists();
    }
}
