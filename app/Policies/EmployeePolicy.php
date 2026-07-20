<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class EmployeePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user)
    {
        return true;
    }

    public function view(User $user, Employee $employee)
    {
        if ($user->hasRole('Administrator')) {
            return true;
        }
        return $user->id === $employee->user_id;
    }


    public function create(User $user)
    {
        return false;
    }

    public function update(User $user, Employee $employee)
    {
        if ($user->hasRole('Administrator')) {
            return true;
        }

        // Employees may edit their own record; the Nova resource locks
        // employment fields so only contact and bank details are writable.
        return $user->id === $employee->user_id;
    }

    public function delete(User $user, Employee $employee)
    {
        return $user->hasRole('Administrator');
    }
}
