<?php

namespace App\Modules\Lifecycle\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Lifecycle\Models\EmploymentContract;

/** Travels with the EmployeeDocument permissions — docs/saas-launch-plan.md §14.5. */
class EmploymentContractPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('EmployeeDocumentView');
    }

    public function view(User $user, EmploymentContract $row): bool
    {
        return $user->hasPermissionTo('EmployeeDocumentView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('EmployeeDocumentCreate');
    }

    public function update(User $user, EmploymentContract $row): bool
    {
        return $user->hasPermissionTo('EmployeeDocumentUpdate');
    }

    public function delete(User $user, EmploymentContract $row): bool
    {
        return $user->hasPermissionTo('EmployeeDocumentDelete');
    }
}
