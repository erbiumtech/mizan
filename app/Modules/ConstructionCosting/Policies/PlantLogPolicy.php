<?php

namespace App\Modules\ConstructionCosting\Policies;

use App\Modules\ConstructionCosting\Models\PlantLog;
use App\Modules\Core\Models\User;

/**
 * Who logs a machine's day, and who prices it — `docs/construction-management-plan.md` §7.3.
 *
 * **Logging is site's**, like a goods receipt and a site sheet: the only people who know whether the excavator worked,
 * stood idle or sat on standby are the people who were there. Approving is somebody else's, because on an owned machine
 * approving books internal hire against the job, and on a hired one it fixes the figure a supplier's invoice will be
 * checked against.
 *
 * **Reversing rides on `ConstructionCostReverse`**, the grant that already governs backing a posted entry out of the
 * ledger — the same decision as reversing a labour record.
 */
class PlantLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ConstructionPlantView');
    }

    public function view(User $user, PlantLog $log): bool
    {
        return $user->can('ConstructionPlantView');
    }

    public function create(User $user): bool
    {
        return $user->can('ConstructionPlantLog');
    }

    public function update(User $user, PlantLog $log): bool
    {
        return $user->can('ConstructionPlantLog') && $log->isDraft();
    }

    public function delete(User $user, PlantLog $log): bool
    {
        return $user->can('ConstructionPlantLog') && $log->isDraft();
    }

    public function approve(User $user, PlantLog $log): bool
    {
        return $user->can('ConstructionPlantApprove') && $log->isDraft();
    }

    public function reverse(User $user, PlantLog $log): bool
    {
        return $user->can('ConstructionCostReverse') && $log->isApproved();
    }
}
