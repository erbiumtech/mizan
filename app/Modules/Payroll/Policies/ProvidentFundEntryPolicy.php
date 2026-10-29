<?php

namespace App\Modules\Payroll\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Payroll\Models\ProvidentFundEntry;

/** Read with payslips; written by hand only with PayslipUpdate; never edited or deleted. */
class ProvidentFundEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('PayslipView');
    }

    public function view(User $user, ProvidentFundEntry $entry): bool
    {
        return $user->hasPermissionTo('PayslipView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('PayslipUpdate');
    }

    public function update(User $user, ProvidentFundEntry $entry): bool
    {
        return false;
    }

    public function delete(User $user, ProvidentFundEntry $entry): bool
    {
        return false;
    }
}
