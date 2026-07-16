<?php

namespace App\Policies;

use App\Models\Payslip;
use App\Models\User;

class PayslipPolicy
{
   public function viewAny(User $user): bool
{
    if (!$user->hasPermissionTo('PayslipView')) {
        return false;
    }

    if ($user->hasRole('Administrator')) {
        return true;
    }

    return true;
}

    public function view(User $user, Payslip $payslip): bool
    {
        if (!$user->hasPermissionTo('PayslipView')) {
            return false;
        }

        if ($user->hasRole('Administrator')) {
            return true;
        }

        return $payslip->employee && $payslip->employee->user_id === $user->id;
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
            || ($payslip->employee && $payslip->employee->user_id === $user->id);
    }
}
