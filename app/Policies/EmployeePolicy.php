<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\User;
use App\Support\EmployeeAccess;
use Illuminate\Auth\Access\HandlesAuthorization;

class EmployeePolicy
{
    use HandlesAuthorization;

    /** Own record or a report in the user's downline. */
    protected function canAccess(User $user, Employee $employee): bool
    {
        return app(EmployeeAccess::class)->accessibleEmployeeIds($user)->contains($employee->id);
    }

    public function viewAny(User $user)
    {
        return true;
    }

    public function view(User $user, Employee $employee)
    {
        if ($user->hasRole('Administrator')) {
            return true;
        }

        return $this->canAccess($user, $employee);
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

        // Employees may edit their own record; managers may also edit their
        // reports (downline). Employment fields stay locked for non-admins.
        return $this->canAccess($user, $employee);
    }

    public function delete(User $user, Employee $employee)
    {
        return $user->hasRole('Administrator');
    }
}
