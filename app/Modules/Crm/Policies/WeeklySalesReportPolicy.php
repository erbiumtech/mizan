<?php

namespace App\Modules\Crm\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Crm\Models\WeeklySalesReport;

/**
 * A rep's own week — docs/saas-launch-plan.md §14.10.
 *
 * Writing one is not a permission: it is the thing the form exists for, and a
 * company that had to grant each rep the right to report their own week would end up
 * with the manager typing them, which is the opposite of the point.
 */
class WeeklySalesReportPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, WeeklySalesReport $report): bool
    {
        return $user->can('OpportunityView') || $report->employee?->user_id === $user->getKey();
    }

    public function create(User $user): bool
    {
        return true;
    }

    /** Your own, or anybody's with the sales right: a figure typed wrong is corrected, not argued with. */
    public function update(User $user, WeeklySalesReport $report): bool
    {
        return $user->can('OpportunityUpdate') || $report->employee?->user_id === $user->getKey();
    }

    public function delete(User $user, WeeklySalesReport $report): bool
    {
        return $user->can('OpportunityUpdate') || $report->employee?->user_id === $user->getKey();
    }
}
