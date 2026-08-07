<?php

namespace App\Modules\Accounting\Policies;

use App\Modules\Accounting\Models\Loan;
use App\Modules\Core\Models\User;

class LoanPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('LoanView');
    }

    public function view(User $user, Loan $loan): bool
    {
        return $user->hasPermissionTo('LoanView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('LoanCreate');
    }

    /**
     * Editing stops once the ledger knows about the loan.
     *
     * The terms are what the schedule was built from, and part of that schedule
     * has been posted. Changing the rate or the principal underneath entries
     * that are already in the books would leave a table nothing agrees with —
     * and the liability would no longer reach zero.
     */
    public function update(User $user, Loan $loan): bool
    {
        return $user->hasPermissionTo('LoanUpdate') && $loan->paidCount() === 0;
    }

    public function delete(User $user, Loan $loan): bool
    {
        return $user->hasPermissionTo('LoanDelete') && $loan->paidCount() === 0;
    }

    /** Raise the draft entry for an instalment. */
    public function record(User $user, Loan $loan): bool
    {
        return $user->hasPermissionTo('LoanRecord') && $loan->is_active;
    }
}
