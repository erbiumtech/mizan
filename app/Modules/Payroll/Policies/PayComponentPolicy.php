<?php

namespace App\Modules\Payroll\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Payroll\Models\PayComponent;

/**
 * Defining what pay is made of is a payroll-configuration act, so it takes the
 * salary-slab permissions rather than the payslip ones: whoever sets the tax slabs
 * is who decides that a fuel allowance exists.
 */
class PayComponentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('SalarySlabView');
    }

    public function view(User $user, PayComponent $component): bool
    {
        return $user->hasPermissionTo('SalarySlabView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('SalarySlabCreate');
    }

    public function update(User $user, PayComponent $component): bool
    {
        return $user->hasPermissionTo('SalarySlabUpdate');
    }

    /**
     * Never the shipped ones, and never one that has been paid.
     *
     * A column-backed component is part of the arithmetic — deleting its row would
     * leave payroll computing a figure nothing can name. One that has appeared on a
     * payslip is the record of what that payslip paid. Switching it off is what stops
     * it being paid.
     */
    public function delete(User $user, PayComponent $component): bool
    {
        return $user->hasPermissionTo('SalarySlabDelete')
            && ! $component->is_column_backed
            && ! $component->payslipAmounts()->exists();
    }
}
