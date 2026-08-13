<?php

namespace App\Modules\Lifecycle\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Lifecycle\Models\FinalSettlement;

class FinalSettlementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('SettlementView');
    }

    public function view(User $user, FinalSettlement $settlement): bool
    {
        return $user->hasPermissionTo('SettlementView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('SettlementCreate');
    }

    /** Only while it is a draft: an approved figure is what somebody committed to. */
    public function update(User $user, FinalSettlement $settlement): bool
    {
        return $user->hasPermissionTo('SettlementUpdate') && $settlement->isDraft();
    }

    /**
     * Approving is its own permission, and never for your own settlement.
     *
     * Checked here rather than only in a service because this is money leaving the
     * company to the person pressing the button — the one place in this application
     * where that is literally true.
     */
    public function approve(User $user, FinalSettlement $settlement): bool
    {
        return $user->hasPermissionTo('SettlementApprove')
            && $settlement->isDraft()
            && $settlement->employee?->user_id !== $user->getKey();
    }

    public function delete(User $user, FinalSettlement $settlement): bool
    {
        return $user->hasPermissionTo('SettlementDelete') && $settlement->isDraft();
    }
}
