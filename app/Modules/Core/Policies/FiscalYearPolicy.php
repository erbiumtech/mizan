<?php

namespace App\Modules\Core\Policies;

use App\Modules\Core\Models\FiscalYear;
use App\Modules\Core\Models\User;

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
