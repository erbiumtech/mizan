<?php

namespace App\Modules\ConstructionCosting\Policies;

use App\Modules\ConstructionCosting\Models\Reconciliation;
use App\Modules\Core\Models\User;

/**
 * Who may prove the two ledgers, and who may accept it when they do not agree.
 *
 * **Running a reconciliation is a read that happens to store its answer**, so it rides on `ConstructionCostView`. It
 * changes no figure in either ledger; a permission that made it hard to run would be a permission that made §4's whole
 * control optional, and §4.3's point is that "a report nobody opens is not a control".
 *
 * **Accepting a difference is `ConstructionPeriodForceClose`**, and §4.3 puts it there by name: the period cannot be
 * closed while unbalanced "unless a user holding `ConstructionPeriodForceClose` accepts the difference **with a stated
 * reason**". It is the sharpest grant in this module because of what it is *not*: it fixes nothing. §4.3's fourth
 * mechanism is the promise that goes with it — "a forced close never fudges the ledger. No plug entry, no balancing
 * figure. Both sides stay true and the difference stays visible in every later period until the cause is fixed."
 *
 * **No update and no delete.** A run is a dated statement about two ledgers on a day. Editing one would restate what
 * somebody accepted and leave their reason attached to a figure they never saw; deleting one would lose the only
 * evidence that a period was ever proved. The way to change a run's answer is to run it again.
 */
class ReconciliationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ConstructionCostView');
    }

    public function view(User $user, Reconciliation $reconciliation): bool
    {
        return $user->can('ConstructionCostView');
    }

    public function create(User $user): bool
    {
        return $user->can('ConstructionCostView');
    }

    public function accept(User $user, Reconciliation $reconciliation): bool
    {
        return $user->can('ConstructionPeriodForceClose')
            && ! $reconciliation->isBalanced()
            && ! $reconciliation->isAccepted();
    }

    public function update(User $user, Reconciliation $reconciliation): bool
    {
        return false;
    }

    public function delete(User $user, Reconciliation $reconciliation): bool
    {
        return false;
    }
}
