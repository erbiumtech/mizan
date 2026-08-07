<?php

namespace App\Modules\Accounting\Policies;

use App\Modules\Accounting\Models\BudgetLine;
use App\Modules\Core\Models\User;

/**
 * A line has no standing of its own — it is one month of one budget — so every
 * ability defers to the budget it belongs to. Registered rather than left to
 * default because Filament treats an unpoliced model as allowed, and the
 * monthly-plan relation manager edits these rows directly.
 */
class BudgetLinePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('BudgetView');
    }

    public function view(User $user, BudgetLine $line): bool
    {
        return $user->hasPermissionTo('BudgetView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('BudgetUpdate');
    }

    public function update(User $user, BudgetLine $line): bool
    {
        return $user->hasPermissionTo('BudgetUpdate')
            && ! ($line->budget?->fiscalYear?->isClosed() ?? false);
    }

    public function delete(User $user, BudgetLine $line): bool
    {
        return $user->hasPermissionTo('BudgetUpdate')
            && ! ($line->budget?->fiscalYear?->isClosed() ?? false);
    }
}
