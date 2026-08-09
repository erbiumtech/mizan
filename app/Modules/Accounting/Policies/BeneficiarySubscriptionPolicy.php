<?php

namespace App\Modules\Accounting\Policies;

use App\Modules\Accounting\Models\BeneficiarySubscription;
use App\Modules\Core\Models\User;

/**
 * A subscription is an instruction to pay money every month, so it is governed by
 * the beneficiary permissions that already gate who may be paid at all.
 */
class BeneficiarySubscriptionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('BeneficiaryView');
    }

    public function view(User $user, BeneficiarySubscription $subscription): bool
    {
        return $user->hasPermissionTo('BeneficiaryView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('BeneficiaryCreate');
    }

    public function update(User $user, BeneficiarySubscription $subscription): bool
    {
        return $user->hasPermissionTo('BeneficiaryUpdate');
    }

    /**
     * Only while it has raised nothing. Once payments exist the subscription is
     * the record of why they were raised, and switching it off is what stops it
     * billing.
     */
    public function delete(User $user, BeneficiarySubscription $subscription): bool
    {
        return $user->hasPermissionTo('BeneficiaryDelete') && ! $subscription->payments()->exists();
    }
}
