<?php

namespace App\Modules\Payroll\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Payroll\Models\PayrollRun;

class PayrollRunPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('PayrollRunView');
    }

    public function view(User $user, PayrollRun $run): bool
    {
        return $user->hasPermissionTo('PayrollRunView');
    }

    /** Runs are made by payroll itself, not by hand. */
    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, PayrollRun $run): bool
    {
        return $user->hasPermissionTo(PayrollRun::LOCK_PERMISSION);
    }

    /**
     * Deleting a month's run would orphan its payslips and lose the record of it
     * having been signed off. Reopening is the way to change a locked month.
     */
    public function delete(User $user, PayrollRun $run): bool
    {
        return false;
    }
}
