<?php

namespace App\Modules\ConstructionContracts\Policies;

use App\Modules\ConstructionContracts\Models\Variation;
use App\Modules\Core\Models\User;

/**
 * Who may raise, price and approve a variation.
 *
 * **Pricing and approving are separate permissions held by separate people**, and §18.2 lists both among the
 * non-CRUD names that matter. Pricing is the surveyor's assessment of what the change is worth; approving it
 * commits the employer's money and moves the figure into the certified contract sum. One person holding both is
 * the segregation of duties this suite keeps everywhere else.
 *
 * There is no `delete` beyond a draft: a variation somebody submitted is correspondence, and the way to end one
 * is to reject it with a reason — which is what an adjudicator will read.
 */
class VariationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ConstructionVariationView');
    }

    public function view(User $user, Variation $variation): bool
    {
        return $user->can('ConstructionVariationView');
    }

    public function create(User $user): bool
    {
        return $user->can('ConstructionVariationCreate');
    }

    /** Editable up to approval; after that its lines are what the parties agreed. */
    public function update(User $user, Variation $variation): bool
    {
        return $user->can('ConstructionVariationUpdate')
            && ! in_array($variation->status, [Variation::STATUS_APPROVED, Variation::STATUS_INCORPORATED], true);
    }

    public function price(User $user, Variation $variation): bool
    {
        return $user->can('ConstructionVariationPrice')
            && in_array($variation->status, [
                Variation::STATUS_SUBMITTED,
                Variation::STATUS_PRICED,
                Variation::STATUS_APPROVED_IN_PRINCIPLE,
            ], true);
    }

    public function approve(User $user, Variation $variation): bool
    {
        return $user->can('ConstructionVariationApprove')
            && in_array($variation->status, [
                Variation::STATUS_SUBMITTED,
                Variation::STATUS_PRICED,
                Variation::STATUS_APPROVED_IN_PRINCIPLE,
            ], true);
    }

    /**
     * Writing it into the schedule is the same decision as approving it, one step later.
     *
     * Kept as its own ability rather than its own permission: the person who agreed the money is the person who
     * says the schedule now reflects it, and a separate grant would be a row in every role form for a decision
     * nobody makes separately (§18.2's Leave precedent).
     */
    public function incorporate(User $user, Variation $variation): bool
    {
        return $user->can('ConstructionVariationApprove') && $variation->status === Variation::STATUS_APPROVED;
    }

    public function delete(User $user, Variation $variation): bool
    {
        return $user->can('ConstructionVariationUpdate') && $variation->status === Variation::STATUS_DRAFT;
    }
}
