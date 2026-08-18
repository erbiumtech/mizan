<?php

namespace App\Modules\ConstructionContracts\Policies;

use App\Modules\ConstructionContracts\Models\ProgressClaim;
use App\Modules\Core\Models\User;

/**
 * Who may prepare and submit a claim.
 *
 * A claim is the contractor's own document, so it sits with whoever maintains the commercial record —
 * `ConstructionCertificateView` and the contract permissions, not a set of its own (§18.2's Leave precedent).
 * Certifying it is a different act with a different permission and, in a real office, a different person.
 *
 * A certified claim is not editable: it is what the certificate was measured against, and a claim that moved
 * afterwards makes "applied versus certified" a comparison of two numbers that never coexisted.
 */
class ProgressClaimPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ConstructionCertificateView');
    }

    public function view(User $user, ProgressClaim $claim): bool
    {
        return $user->can('ConstructionCertificateView');
    }

    public function create(User $user): bool
    {
        return $user->can('ConstructionCertificateCreate');
    }

    public function update(User $user, ProgressClaim $claim): bool
    {
        return $user->can('ConstructionCertificateCreate') && $claim->isEditable();
    }

    public function delete(User $user, ProgressClaim $claim): bool
    {
        return $user->can('ConstructionCertificateCreate') && $claim->status === ProgressClaim::STATUS_DRAFT;
    }
}
