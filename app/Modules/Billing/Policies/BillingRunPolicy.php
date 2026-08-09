<?php

namespace App\Modules\Billing\Policies;

use App\Modules\Billing\Models\BillingRun;
use App\Modules\Core\Models\User;

class BillingRunPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('BillingRunView');
    }

    public function view(User $user, BillingRun $run): bool
    {
        return $user->hasPermissionTo('BillingRunView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('BillingRunCreate');
    }

    public function update(User $user, BillingRun $run): bool
    {
        return $user->hasPermissionTo('BillingRunUpdate');
    }

    /**
     * Only while the invoice it built is still a draft. Once issued, the invoice
     * is posted to the ledger and in the client's hands, and the run is the record
     * of how its figures were arrived at.
     */
    public function delete(User $user, BillingRun $run): bool
    {
        return $user->hasPermissionTo('BillingRunDelete') && $run->isRebuildable();
    }
}
