<?php

namespace App\Modules\ConstructionCosting\Policies;

use App\Modules\ConstructionCosting\Models\LabourRate;
use App\Modules\Core\Models\User;

/**
 * Who decides what an hour costs — `docs/construction-management-plan.md` §7.2.
 *
 * `ConstructionLabourRateSet` is its own permission because it is its own decision, and it is the one that reaches
 * every job at once: a company-default rate revised by ten per cent changes the labour cost of everything anybody
 * books from that date. Reading the register rides on `ConstructionLabourView` — the site needs to know what it is
 * being charged with.
 *
 * **Deleting is allowed only for a rate that has not started yet.** Once a rate has been in force, something has
 * probably snapshotted from it — §7.1 freezes `cost_rate_per_hour` on the labour record at approval precisely so the
 * cost survives a later change here — and a deleted row leaves that snapshot with nothing to explain it. A rate typed
 * against the wrong scope and caught the same afternoon is the case this allows for.
 */
class LabourRatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ConstructionLabourView');
    }

    public function view(User $user, LabourRate $rate): bool
    {
        return $user->can('ConstructionLabourView');
    }

    public function create(User $user): bool
    {
        return $user->can('ConstructionLabourRateSet');
    }

    public function update(User $user, LabourRate $rate): bool
    {
        return $user->can('ConstructionLabourRateSet');
    }

    public function delete(User $user, LabourRate $rate): bool
    {
        return $user->can('ConstructionLabourRateSet')
            && $rate->effective_from !== null
            && $rate->effective_from->isFuture();
    }
}
