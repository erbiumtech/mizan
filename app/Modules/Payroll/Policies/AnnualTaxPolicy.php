<?php

namespace App\Modules\Payroll\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Payroll\Models\AnnualTax;

class AnnualTaxPolicy
{
    /**
     * Create a new policy instance.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('AnnualTaxView');
    }

    public function view(User $user, AnnualTax $annualTax): bool
    {
        return $user->hasPermissionTo('AnnualTaxView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('AnnualTaxCreate');
    }

    public function update(User $user, AnnualTax $annualTax): bool
    {
        return $user->hasPermissionTo('AnnualTaxUpdate');
    }

    public function delete(User $user, AnnualTax $annualTax): bool
    {
        return $user->hasPermissionTo('AnnualTaxDelete');
    }
}
