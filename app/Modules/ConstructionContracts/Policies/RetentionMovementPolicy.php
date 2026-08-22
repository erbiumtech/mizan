<?php

namespace App\Modules\ConstructionContracts\Policies;

use App\Modules\ConstructionContracts\Models\RetentionMovement;
use App\Modules\Core\Models\User;

/**
 * Who may read the retention ledger and move money in it.
 *
 * Reading rides on the certificate permission: it is the same screenful of facts about the same contract, and a
 * separate view grant would be a row in every role form for a decision nobody makes separately (§18.2).
 *
 * **`ConstructionRetentionRelease` is its own permission**, which §18.2 lists among the names that matter.
 * Releasing hands back money the contract entitled this company to hold — on a job of any size the largest single
 * payment decision anybody makes — and forfeiting takes money the other party earned. Both belong to whoever
 * answers for the figure at final account.
 *
 * **There is no `update` and no `delete`.** A movement is an event. Correcting one is another movement — an
 * `adjusted` or a `reinstated` row with a reason — which is the same discipline the cost ledger keeps with
 * reversals, and for the same reason: a balance whose history depends on what somebody removed is not a ledger.
 */
class RetentionMovementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ConstructionCertificateView');
    }

    public function view(User $user, RetentionMovement $movement): bool
    {
        return $user->can('ConstructionCertificateView');
    }

    /** Only the non-automatic kinds are created by hand; `held` comes from a certificate being issued. */
    public function create(User $user): bool
    {
        return $user->can('ConstructionRetentionRelease');
    }

    public function release(User $user, RetentionMovement $movement): bool
    {
        return $user->can('ConstructionRetentionRelease');
    }
}
