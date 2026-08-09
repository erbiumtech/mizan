<?php

namespace App\Modules\Accounting\Policies;

use App\Modules\Accounting\Models\LoanInstalment;
use App\Modules\Core\Models\User;

/**
 * Rows of a generated table, not records anybody authors. Nothing here creates,
 * edits or deletes one — the schedule is the loan's, and it is rebuilt as a
 * whole or not at all.
 */
class LoanInstalmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('LoanView');
    }

    public function view(User $user, LoanInstalment $instalment): bool
    {
        return $user->hasPermissionTo('LoanView');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, LoanInstalment $instalment): bool
    {
        return false;
    }

    public function delete(User $user, LoanInstalment $instalment): bool
    {
        return false;
    }
}
