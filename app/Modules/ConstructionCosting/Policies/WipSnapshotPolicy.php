<?php

namespace App\Modules\ConstructionCosting\Policies;

use App\Modules\ConstructionCosting\Models\WipSnapshot;
use App\Modules\Core\Models\User;

/**
 * Who may compute a work-in-progress position, and who may freeze one.
 *
 * **Computing rides on `ConstructionCostView`.** An unlocked position is a report: it recomputes every time somebody
 * opens it, and the figures come from the forecast, the measurements and the certificates rather than from anything
 * decided here.
 *
 * **Locking is `ConstructionPeriodClose`**, and that is the same grant for the same reason. §4.4: the month "was signed
 * off, reported to a bank and used to compute a bonus". Freezing a WIP position and closing a cost period are one
 * decision made at one moment by one person, and giving them separate names would let a month be closed on figures
 * nobody froze — or frozen figures sit against a month still taking cost.
 *
 * **Posting the movement is `ConstructionGlPost`**, because it writes a journal entry into the general ledger. That
 * boundary is the whole of §4.1's argument and it does not soften for WIP.
 *
 * **No update and no delete.** An unlocked snapshot is recomputed rather than edited — editing one would put a figure in
 * a position that no forecast or measurement produced. A locked one is what somebody signed.
 */
class WipSnapshotPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ConstructionCostView');
    }

    public function view(User $user, WipSnapshot $snapshot): bool
    {
        return $user->can('ConstructionCostView');
    }

    public function create(User $user): bool
    {
        return $user->can('ConstructionCostView');
    }

    public function lock(User $user, WipSnapshot $snapshot): bool
    {
        return $user->can('ConstructionPeriodClose') && ! $snapshot->isLocked();
    }

    public function post(User $user, WipSnapshot $snapshot): bool
    {
        return $user->can('ConstructionGlPost')
            && $snapshot->isLocked()
            && ! $snapshot->isPosted();
    }

    public function update(User $user, WipSnapshot $snapshot): bool
    {
        return false;
    }

    public function delete(User $user, WipSnapshot $snapshot): bool
    {
        return false;
    }
}
