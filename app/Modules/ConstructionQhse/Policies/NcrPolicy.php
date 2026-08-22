<?php

namespace App\Modules\ConstructionQhse\Policies;

use App\Modules\ConstructionQhse\Models\Ncr;
use App\Modules\Core\Models\User;

/**
 * Who raises a non-conformance, who dispositions it, and who proposes withholding money — §17.2.
 *
 * **Three grants, and the middle one is the interesting decision.** Raising an NCR is site's and quality's: anybody who
 * can see the work is wrong should be able to say so, and a register that made that difficult is a register that records
 * the nonconformities somebody remembered to mention.
 *
 * **`ConstructionNcrDisposition` is its own permission** because §17.2 calls disposition "the field that decides whether
 * money changes hands". *Use as is* and *concession requested* accept work that does not meet the specification — the
 * client giving something up — and that is not a call for whoever noticed the defect.
 *
 * **Proposing a deduction is the same grant as dispositioning**, deliberately: the two decisions are made in the same
 * conversation, and the proposal withholds nothing on its own. The act that moves money is on the *other* side of the
 * boundary entirely, taken by whoever signs the certificate.
 *
 * **There is no delete.** An NCR is voided with a reason and keeps its number, exactly as §16.2's cancelled RFI does.
 */
class NcrPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ConstructionNcrView');
    }

    public function view(User $user, Ncr $ncr): bool
    {
        return $user->can('ConstructionNcrView');
    }

    public function create(User $user): bool
    {
        return $user->can('ConstructionNcrUpdate');
    }

    public function update(User $user, Ncr $ncr): bool
    {
        return $user->can('ConstructionNcrUpdate') && ! $ncr->isClosed();
    }

    /** The field that decides whether money changes hands. See the class docblock. */
    public function disposition(User $user, Ncr $ncr): bool
    {
        return $user->can('ConstructionNcrDisposition') && ! $ncr->isClosed();
    }

    /** A proposal, and it withholds nothing — the signature that does is on the certificate. */
    public function proposeDeduction(User $user, Ncr $ncr): bool
    {
        return $user->can('ConstructionNcrDisposition') && ! $ncr->isClosed();
    }

    /** Verifying is quality's own act: it is the re-inspection that makes a closure evidence. */
    public function verify(User $user, Ncr $ncr): bool
    {
        return $user->can('ConstructionNcrUpdate') && ! $ncr->isClosed();
    }

    public function close(User $user, Ncr $ncr): bool
    {
        return $user->can('ConstructionNcrUpdate') && ! $ncr->isClosed();
    }

    /**
     * Voiding an NCR says it was never a nonconformity, which is a statement about the specification rather than about
     * the work — so it sits with whoever may disposition.
     */
    public function void(User $user, Ncr $ncr): bool
    {
        return $user->can('ConstructionNcrDisposition') && ! $ncr->isClosed();
    }

    public function delete(User $user, Ncr $ncr): bool
    {
        return false;
    }
}
