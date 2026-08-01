<?php

namespace App\Modules\Advances\Policies;

use App\Modules\Advances\Models\Advance;
use App\Modules\Core\Models\User;

class AdvancePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('AdvanceView');
    }

    public function view(User $user, Advance $advance): bool
    {
        return $user->hasPermissionTo('AdvanceView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('AdvanceCreate');
    }

    public function update(User $user, Advance $advance): bool
    {
        return $user->hasPermissionTo('AdvanceUpdate');
    }

    /**
     * Only while nothing has been recovered. Once payroll has taken instalments
     * the advance is the record of them, and deleting it would leave payslips
     * showing a deduction against something that no longer exists.
     */
    public function delete(User $user, Advance $advance): bool
    {
        return $user->hasPermissionTo('AdvanceDelete') && $advance->recoveredAmount() <= 0;
    }
}
