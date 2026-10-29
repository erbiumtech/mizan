<?php

namespace App\Modules\Employees\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\EmployeeVerification;

/**
 * A verification is the employee's own — docs/saas-launch-plan.md §14.8.
 *
 * Nobody creates or edits one by hand: a row is written by issuing a code and by
 * typing one back, and a verification somebody could set by hand would prove
 * nothing. So there is no create, no update and no delete here — only who may look.
 */
class EmployeeVerificationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('EmployeeView');
    }

    public function view(User $user, EmployeeVerification $verification): bool
    {
        return $user->can('EmployeeView') || $verification->employee?->user_id === $user->getKey();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, EmployeeVerification $verification): bool
    {
        return false;
    }

    public function delete(User $user, EmployeeVerification $verification): bool
    {
        return false;
    }
}
