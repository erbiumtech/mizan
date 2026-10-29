<?php

namespace App\Modules\Performance\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use App\Modules\Performance\Models\DailyReport;

/**
 * Filing is any employee's, about themselves; reading is `ReportView` over the
 * rows the resource scopes to you; deciding is `ReportDecide`, because a
 * decision writes the ledger.
 */
class DailyReportPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('DailyReportView');
    }

    public function view(User $user, DailyReport $report): bool
    {
        return $user->hasPermissionTo('DailyReportView');
    }

    public function create(User $user): bool
    {
        return Employee::query()->where('user_id', $user->getKey())->where('is_active', true)->exists();
    }

    public function update(User $user, DailyReport $report): bool
    {
        return ! $report->isDecided() && $report->employee?->user_id === $user->getKey();
    }

    public function decide(User $user, DailyReport $report): bool
    {
        return $user->hasPermissionTo('DailyReportDecide');
    }

    public function delete(User $user, DailyReport $report): bool
    {
        return false;
    }
}
