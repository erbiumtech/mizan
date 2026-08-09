<?php

namespace App\Modules\Payroll\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Payroll\Models\Payslip;
use App\Support\EmployeeAccess;

class PayslipPolicy
{
    /** Own record or a report in the user's downline. */
    protected function canAccessPayslip(User $user, Payslip $payslip): bool
    {
        return $payslip->employee_id
            && app(EmployeeAccess::class)->accessibleEmployeeIds($user)->contains($payslip->employee_id);
    }

    public function viewAny(User $user): bool
    {
        if (! $user->hasPermissionTo('PayslipView')) {
            return false;
        }

        if ($user->hasRole('Administrator')) {
            return true;
        }

        return true;
    }

    public function view(User $user, Payslip $payslip): bool
    {
        if (! $user->hasPermissionTo('PayslipView')) {
            return false;
        }

        if ($user->hasRole('Administrator')) {
            return true;
        }

        return $this->canAccessPayslip($user, $payslip);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('PayslipCreate');
    }

    public function update(User $user, Payslip $payslip): bool
    {
        return $user->hasPermissionTo('PayslipUpdate');
    }

    public function delete(User $user, Payslip $payslip): bool
    {
        return $user->hasPermissionTo('PayslipDelete');
    }

    /**
     * Without this Nova falls back to update() for actions; the owning
     * employee must be able to run Accept/Reject (and Download) on
     * their own payslip.
     */
    public function runAction(User $user, Payslip $payslip): bool
    {
        return $user->hasPermissionTo('PayslipUpdate')
            || $this->canAccessPayslip($user, $payslip);
    }
}
