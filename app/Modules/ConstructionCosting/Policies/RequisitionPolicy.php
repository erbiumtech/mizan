<?php

namespace App\Modules\ConstructionCosting\Policies;

use App\Modules\ConstructionCosting\Models\Requisition;
use App\Modules\Core\Models\User;

/**
 * Who may ask, and who may agree — `docs/construction-management-plan.md` §5.
 *
 * **This is the first construction permission set where site staff get a `create`**, and that is the point of the
 * document: the demand comes from the people who need the material. A requisition raised only by the commercial
 * office is a purchase order with an extra step.
 *
 * **Approving is somebody else's**, and it commits nothing — it says the need is real. The money is committed when
 * the order that follows is *issued*, under `ConstructionCommitmentIssue`. Two gates, in that order, which is what
 * lets site ask freely without anybody worrying that asking spends anything.
 *
 * Ordering from a request needs the buyer's grant rather than one of these, because raising the order is the
 * commitment side of the chain: see `CommitmentPolicy`.
 */
class RequisitionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ConstructionRequisitionView');
    }

    public function view(User $user, Requisition $requisition): bool
    {
        return $user->can('ConstructionRequisitionView');
    }

    public function create(User $user): bool
    {
        return $user->can('ConstructionRequisitionCreate');
    }

    /** Editable while it is a draft or has been rejected: "not like that, like this" is the usual answer. */
    public function update(User $user, Requisition $requisition): bool
    {
        return $user->can('ConstructionRequisitionCreate') && $requisition->isEditable();
    }

    public function approve(User $user, Requisition $requisition): bool
    {
        return $user->can('ConstructionRequisitionApprove')
            && in_array($requisition->status, [
                Requisition::STATUS_DRAFT,
                Requisition::STATUS_SUBMITTED,
                Requisition::STATUS_APPROVED,
            ], true);
    }

    /** A draft nobody has approved may be deleted; anything else is cancelled with a reason. */
    public function delete(User $user, Requisition $requisition): bool
    {
        return $user->can('ConstructionRequisitionCreate') && $requisition->status === Requisition::STATUS_DRAFT;
    }
}
