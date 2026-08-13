<?php

namespace App\Modules\Leave\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Leave\Models\LeaveEntitlement;

class LeaveEntitlementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('LeaveEntitlementView');
    }

    public function view(User $user, LeaveEntitlement $entitlement): bool
    {
        return $user->hasPermissionTo('LeaveEntitlementView');
    }

    /**
     * Entitlements are normally opened by the command, not typed.
     *
     * Creating one by hand is still allowed, because a type added mid-year needs a
     * row before anybody can take it and waiting for the nightly run is not an
     * answer.
     */
    public function create(User $user): bool
    {
        return $user->hasPermissionTo('LeaveEntitlementCreate');
    }

    public function update(User $user, LeaveEntitlement $entitlement): bool
    {
        return $user->hasPermissionTo('LeaveEntitlementUpdate');
    }

    /**
     * Never once leave has been taken against the year.
     *
     * Deleting an entitlement with leave_days behind it leaves the days orphaned:
     * they stay charged to the employee while the credits that offset them are gone,
     * so the balance goes negative for a reason nobody can find. A correction is an
     * adjustment row, which is exactly what that table is for.
     */
    public function delete(User $user, LeaveEntitlement $entitlement): bool
    {
        return $user->hasPermissionTo('LeaveEntitlementDelete')
            && ! $entitlement->adjustments()->exists();
    }
}
