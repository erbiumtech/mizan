<?php

namespace App\Modules\ConstructionCosting\Policies;

use App\Modules\ConstructionCosting\Models\CostPeriod;
use App\Modules\Core\Models\User;

/**
 * Who may close a cost period.
 *
 * Closing fixes the figures a client certificate and a WIP snapshot are built on, so it sits with the approval
 * powers. **Reopening is absent entirely**: §3.4 is explicit that reopening a signed-off period to slot one
 * invoice in invalidates everything that depended on that period's total, and the answer is a late cost in the
 * open period rather than a door back into a closed one.
 */
class CostPeriodPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ConstructionCostView');
    }

    public function view(User $user, CostPeriod $period): bool
    {
        return $user->can('ConstructionCostView');
    }

    public function close(User $user, CostPeriod $period): bool
    {
        return $user->can('ConstructionPeriodClose') && $period->isOpen();
    }

    /** Closing over an unexplained difference between the two ledgers needs a name on it. */
    public function forceClose(User $user, CostPeriod $period): bool
    {
        return $user->can('ConstructionPeriodForceClose') && $period->isOpen();
    }
}
