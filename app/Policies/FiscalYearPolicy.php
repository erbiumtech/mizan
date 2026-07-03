<?php

namespace App\Policies;

use App\Models\User;
use App\Nova\FiscalYear;

class FiscalYearPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('FiscalYearView');
    }

    public function view(User $user, FiscalYear $fiscalYear): bool
    {
        return $user->hasPermissionTo('FiscalYearView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('FiscalYearCreate');
    }

    public function update(User $user, FiscalYear $fiscalYear): bool
    {
        return $user->hasPermissionTo('FiscalYearUpdate');
    }

    public function delete(User $user, FiscalYear $fiscalYear): bool
    {
        return $user->hasPermissionTo('FiscalYearDelete');
    }
}
