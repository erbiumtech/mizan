<?php

namespace App\Modules\Employees\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
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

    /**
     * Staff who never sign in are created here; staff who do are created through
     * Users, which makes the login and the employee record together.
     *
     * This used to be a hard false, because an employee was by definition
     * somebody with an account. That stopped being true once a household could
     * employ a driver or a cook: they are employed and they get paid, and
     * inventing an email address and a password for them to never use is worse
     * than letting the record stand on its own.
     *
     * Note create is the one ability the Gate::before bypass does NOT grant, so
     * this really is the check for everybody, Administrator included.
     */
    public function create(User $user)
    {
        return $user->isAdministrator() || $user->hasPermissionTo('EmployeeUpdate');
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

    /**
     * Subject access — docs/saas-launch-plan.md §14.8.
     *
     * Everything the company holds about one person, in one file. A narrower right
     * than viewing the record, because the file gathers what six modules hold into
     * something that can be carried out of the building in one click.
     */
    public function disclose(User $user, Employee $employee): bool
    {
        return $user->can('EmployeePrivacy');
    }

    /**
     * Erasure. The same permission and one further condition: somebody still employed
     * cannot be erased, so a record nobody meant to empty cannot be emptied by a
     * misclick on a live row. {@see \App\Modules\Employees\Services\EmployeeErasure}
     * refuses it again, because a policy is not where a rule like that should only live.
     */
    public function erase(User $user, Employee $employee): bool
    {
        return $user->can('EmployeePrivacy') && ! $employee->is_active;
    }
}
