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

    /**
     * Reading one payslip.
     *
     * **`isPrivileged()` rather than `hasRole('Administrator')`, and the difference had no symptom until
     * there was a page to symptom on.** The payslips *list* scopes its query with
     * `EmployeeAccess::scopeAccessibleEmployees()`, which lets Administrator, Accountant, Manager and CEO see
     * every row; this method let only an Administrator open one. Nothing routed through it — the row actions
     * ask `runAction()` and Edit asks `update()` — so an Accountant could see, download and edit a payslip
     * they were not allowed to *view*. `ViewPayslip` is the first screen to ask, and a list that offers a row
     * the record page then refuses is the kind of inconsistency somebody reports as a permissions bug.
     *
     * This widens nothing real: those four roles already read every payslip in the list, download the PDF and
     * edit the figures. Everybody else is still their own record and their reporting downline.
     */
    public function view(User $user, Payslip $payslip): bool
    {
        if (! $user->hasPermissionTo('PayslipView')) {
            return false;
        }

        if (app(EmployeeAccess::class)->isPrivileged($user)) {
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
