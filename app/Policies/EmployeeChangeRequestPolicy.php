<?php

namespace App\Policies;

use App\Models\EmployeeChangeRequest;
use App\Models\User;

class EmployeeChangeRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return true; // index is scoped to own requests for non-approvers
    }

    public function view(User $user, EmployeeChangeRequest $changeRequest): bool
    {
        return $user->can('EmployeeChangeApprove')
            || $user->id === $changeRequest->requested_by;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, EmployeeChangeRequest $changeRequest): bool
    {
        return false;
    }

    /**
     * Nova falls back to update() for running actions unless this
     * exists; requests are immutable but approvers may Approve/Reject.
     */
    public function runAction(User $user, EmployeeChangeRequest $changeRequest): bool
    {
        return $user->can('EmployeeChangeApprove');
    }

    public function delete(User $user, EmployeeChangeRequest $changeRequest): bool
    {
        return $user->hasRole('Administrator');
    }
}
