<?php

namespace App\Modules\Expenses\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Expenses\Models\ExpenseClaim;

class ExpenseClaimPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('ExpenseClaimView');
    }

    public function view(User $user, ExpenseClaim $claim): bool
    {
        return $user->hasPermissionTo('ExpenseClaimView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('ExpenseClaimCreate');
    }

    /**
     * Only while nobody has decided it. An approved claim is a commitment to pay and
     * a refused one is a decision on the record; editing either afterwards would
     * change what was agreed without anyone agreeing to it.
     */
    public function update(User $user, ExpenseClaim $claim): bool
    {
        return $user->hasPermissionTo('ExpenseClaimUpdate') && $claim->isPending();
    }

    public function delete(User $user, ExpenseClaim $claim): bool
    {
        return $user->hasPermissionTo('ExpenseClaimDelete') && $claim->isPending();
    }

    /** Deciding is its own permission: submitting a claim is not approving one. */
    public function decide(User $user, ExpenseClaim $claim): bool
    {
        return $user->hasPermissionTo(ExpenseClaim::APPROVE_PERMISSION)
            && $claim->isPending()
            && $claim->submitted_by !== $user->getKey();
    }
}
