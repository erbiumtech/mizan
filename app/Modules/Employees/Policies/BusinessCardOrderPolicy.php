<?php

namespace App\Modules\Employees\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\BusinessCardOrder;

/**
 * Anybody may ask; somebody with `EmployeeUpdate` decides — docs/saas-launch-plan.md §14.10.
 *
 * The same rule as a letter request, for the same reason: a person needing cards is
 * the ordinary case, and a company that had to grant each person the right to ask
 * would end up with the office manager raising the orders, which is the queue this
 * replaces.
 */
class BusinessCardOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, BusinessCardOrder $order): bool
    {
        return $user->can('EmployeeView') || $order->employee?->user_id === $user->getKey();
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, BusinessCardOrder $order): bool
    {
        return $user->can('EmployeeUpdate');
    }

    /** Withdrawn by the person who asked, while nobody has acted on it. */
    public function delete(User $user, BusinessCardOrder $order): bool
    {
        return $order->status === BusinessCardOrder::STATUS_REQUESTED
            && ($user->can('EmployeeUpdate') || $order->employee?->user_id === $user->getKey());
    }
}
