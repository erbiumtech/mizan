<?php

namespace App\Modules\ConstructionContracts\Policies;

use App\Modules\ConstructionContracts\Models\BackCharge;
use App\Modules\Core\Models\User;

/**
 * Who raises a back-charge and who deducts it — `docs/construction-management-plan.md` §12.
 *
 * **Raising and notifying are the same grant, and applying is not.** Raising the charge is bookkeeping and serving
 * notice is the surveyor's ordinary job — both belong with whoever administers the subcontract. Taking the money off a
 * payment is a different act: it reduces what another company is paid, and under most subcontracts it is the deduction
 * rather than the notice that gets adjudicated.
 *
 * That asymmetry is the same one the certificate keeps: preparing is the surveyor's, certifying is the approver's.
 *
 * **A draft may be edited; nothing else may.** Once notice is served the figure is what the subcontractor was told, and
 * the way to change it is `agree()` with the settled amount kept beside the original.
 */
class BackChargePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ConstructionBackChargeView');
    }

    public function view(User $user, BackCharge $charge): bool
    {
        return $user->can('ConstructionBackChargeView');
    }

    public function create(User $user): bool
    {
        return $user->can('ConstructionBackChargeUpdate');
    }

    /** Drafts only: an edit after notice would move a figure the other party is relying on. */
    public function update(User $user, BackCharge $charge): bool
    {
        return $user->can('ConstructionBackChargeUpdate') && $charge->isEditable();
    }

    /** Serving notice, which is what makes the charge recoverable at all. */
    public function notify(User $user, BackCharge $charge): bool
    {
        return $user->can('ConstructionBackChargeUpdate') && $charge->isDraft();
    }

    /** Recording the subcontractor's objection — a fact about the account, so whoever keeps the account records it. */
    public function dispute(User $user, BackCharge $charge): bool
    {
        return $user->can('ConstructionBackChargeUpdate')
            && $charge->isNotified()
            && ! $charge->isApplied()
            && $charge->status !== BackCharge::STATUS_WITHDRAWN;
    }

    /**
     * Settling the amount, which is the approval grant rather than the filing one.
     *
     * Agreeing 180,000 against a notice of 240,000 gives away 60,000 of a recovery the company was entitled to. That is
     * the same shape of decision as releasing retention, and it sits in the same place.
     */
    public function agree(User $user, BackCharge $charge): bool
    {
        return $user->can('ConstructionBackChargeApply')
            && $charge->isNotified()
            && ! $charge->isApplied()
            && $charge->status !== BackCharge::STATUS_WITHDRAWN;
    }

    /** Taking the money off a payment. */
    public function apply(User $user, BackCharge $charge): bool
    {
        return $user->can('ConstructionBackChargeApply') && $charge->isApplicable();
    }

    /** And taking it back off, while the certificate is still a draft. */
    public function unapply(User $user, BackCharge $charge): bool
    {
        return $user->can('ConstructionBackChargeApply') && $charge->isApplied();
    }

    /**
     * Dropping the charge entirely.
     *
     * The approval grant, not the filing one: withdrawing writes off a recovery, which is the same decision as agreeing
     * one for less — and a charge that can be raised and dropped by one person is a charge nobody has to justify.
     */
    public function withdraw(User $user, BackCharge $charge): bool
    {
        return $user->can('ConstructionBackChargeApply')
            && ! $charge->isApplied()
            && $charge->status !== BackCharge::STATUS_WITHDRAWN;
    }

    /**
     * There is no delete.
     *
     * A back-charge somebody was told about is a fact about the account whether or not the company still pursues it —
     * `withdraw()` with a reason is the honest way out, and it is the row that explains why the final account does not
     * add up to the notices. The retention ledger keeps the same discipline for the same reason.
     */
    public function delete(User $user, BackCharge $charge): bool
    {
        return false;
    }
}
