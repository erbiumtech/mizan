<?php

namespace App\Modules\Expenses\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Expenses\Models\ExpenseClaimApproval;

/** Travels with the claim's permissions. */
class ExpenseClaimApprovalPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('ExpenseClaimView');
    }

    public function view(User $user, ExpenseClaimApproval $row): bool
    {
        return $user->hasPermissionTo('ExpenseClaimView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('ExpenseClaimCreate');
    }

    public function update(User $user, ExpenseClaimApproval $row): bool
    {
        return $user->hasPermissionTo('ExpenseClaimUpdate');
    }

    public function delete(User $user, ExpenseClaimApproval $row): bool
    {
        return $user->hasPermissionTo('ExpenseClaimUpdate');
    }
}
