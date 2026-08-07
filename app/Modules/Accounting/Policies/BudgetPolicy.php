<?php

namespace App\Modules\Accounting\Policies;

use App\Modules\Accounting\Models\Budget;
use App\Modules\Core\Models\User;

class BudgetPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('BudgetView');
    }

    public function view(User $user, Budget $budget): bool
    {
        return $user->hasPermissionTo('BudgetView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('BudgetCreate');
    }

    public function update(User $user, Budget $budget): bool
    {
        // A closed year's plan is history. Nothing can post into it any more
        // (FiscalYearClosingService freezes the ledger), so the only thing
        // editing the plan could change is what last year is measured against —
        // which is the one number a variance report must not be able to move.
        return $user->hasPermissionTo('BudgetUpdate')
            && ! ($budget->fiscalYear?->isClosed() ?? false);
    }

    public function delete(User $user, Budget $budget): bool
    {
        return $user->hasPermissionTo('BudgetDelete')
            && ! ($budget->fiscalYear?->isClosed() ?? false);
    }
}
