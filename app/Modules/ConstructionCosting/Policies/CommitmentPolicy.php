<?php

namespace App\Modules\ConstructionCosting\Policies;

use App\Modules\ConstructionCosting\Models\Commitment;
use App\Modules\Core\Models\User;

/**
 * Who may raise, approve, issue and close a commitment — `docs/construction-management-plan.md` §5.
 *
 * **Approve, issue and close are three permissions because they are three decisions**, and the middle one is the
 * one people skip when reading this: approving says the company will spend the money, *issuing* tells the supplier.
 * Only the second makes it a promise somebody else is relying on, which is why only the second puts the figure on
 * the committed column.
 *
 * **Closing is the CEO's**, because it writes off money that was committed. "The supplier delivered short and we
 * agreed to leave it" and "somebody forgot" produce the same number and are not the same event; the reason column is
 * what tells them apart, and the permission is what makes somebody own it.
 *
 * **There is no delete.** A commitment that was issued is a document a supplier holds. Cancelling it is a state with
 * a reason, and the reliefs it writes are what take the money back off the committed column — a deleted order would
 * take its own history with it.
 */
class CommitmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ConstructionCommitmentView');
    }

    public function view(User $user, Commitment $commitment): bool
    {
        return $user->can('ConstructionCommitmentView');
    }

    public function create(User $user): bool
    {
        return $user->can('ConstructionCommitmentCreate');
    }

    /** Editable only while it is a draft: the supplier is working to the copy they were sent. */
    public function update(User $user, Commitment $commitment): bool
    {
        return $user->can('ConstructionCommitmentCreate') && $commitment->isDraft();
    }

    public function approve(User $user, Commitment $commitment): bool
    {
        return $user->can('ConstructionCommitmentApprove')
            && in_array($commitment->status, [Commitment::STATUS_DRAFT, Commitment::STATUS_PENDING_APPROVAL], true);
    }

    /** The act that makes it a commitment, which is why it is not the same grant as approving. */
    public function issue(User $user, Commitment $commitment): bool
    {
        return $user->can('ConstructionCommitmentIssue') && $commitment->status === Commitment::STATUS_APPROVED;
    }

    /** Closing writes off whatever is still open, so it needs the grant that carries a name. */
    public function close(User $user, Commitment $commitment): bool
    {
        return $user->can('ConstructionCommitmentClose') && ! $commitment->isClosed();
    }
}
