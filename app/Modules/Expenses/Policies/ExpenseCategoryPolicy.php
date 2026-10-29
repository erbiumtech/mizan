<?php

namespace App\Modules\Expenses\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Expenses\Models\ExpenseCategory;

/** Travels with the claim's permissions. */
class ExpenseCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('ExpenseClaimView');
    }

    public function view(User $user, ExpenseCategory $row): bool
    {
        return $user->hasPermissionTo('ExpenseClaimView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('ExpenseClaimApprove');
    }

    public function update(User $user, ExpenseCategory $row): bool
    {
        return $user->hasPermissionTo('ExpenseClaimApprove');
    }

    public function delete(User $user, ExpenseCategory $row): bool
    {
        return $user->hasPermissionTo('ExpenseClaimApprove');
    }
}
