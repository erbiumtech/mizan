<?php

namespace App\Modules\Payroll\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Payroll\Models\PayAdjustment;

/** An adjustment is a payslip input: the payslip permissions, and never once the month is signed off. */
class PayAdjustmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('PayslipView');
    }

    public function view(User $user, PayAdjustment $adjustment): bool
    {
        return $user->hasPermissionTo('PayslipView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('PayslipUpdate');
    }

    public function update(User $user, PayAdjustment $adjustment): bool
    {
        return $user->hasPermissionTo('PayslipUpdate') && ! $adjustment->isFrozen();
    }

    public function delete(User $user, PayAdjustment $adjustment): bool
    {
        return $this->update($user, $adjustment);
    }
}
