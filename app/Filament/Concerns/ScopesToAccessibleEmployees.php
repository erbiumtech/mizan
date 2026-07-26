<?php

namespace App\Filament\Concerns;

use App\Support\EmployeeAccess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Shared "own + downline" scoping for employee-keyed resources.
 *
 * Privileged roles (Administrator/Accountant/Manager/CEO) keep full access;
 * everyone else is limited to their own employee record plus their entire
 * reporting downline, resolved once per request by {@see EmployeeAccess}.
 *
 * Each resource picks the column it keys on — `id` for Employee, `employee_id`
 * for Payslip/EmployeeSetting/AnnualTax, `user_id` for MPR.
 */
trait ScopesToAccessibleEmployees
{
    protected static function userIsPrivileged(): bool
    {
        return app(EmployeeAccess::class)->isPrivileged(Auth::user());
    }

    /**
     * Accessible employee ids (own + downline) for the current user.
     *
     * @return Collection<int, int>
     */
    protected static function accessibleEmployeeIds(): Collection
    {
        $user = Auth::user();

        return $user ? app(EmployeeAccess::class)->accessibleEmployeeIds($user) : collect();
    }

    /**
     * Accessible user ids (own + downline) for the current user.
     *
     * @return Collection<int, int>
     */
    protected static function accessibleUserIds(): Collection
    {
        $user = Auth::user();

        return $user ? app(EmployeeAccess::class)->accessibleUserIds($user) : collect();
    }
}
