<?php

namespace App\Modules\Leave\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Leave\Models\LeaveAdjustment;

/**
 * An adjustment is part of the entitlement it corrects, so it borrows the
 * entitlement's permissions rather than owning a group of its own — four more
 * permission names for a table nobody navigates to directly would be four more rows
 * in every role form for no decision anybody makes separately.
 */
class LeaveAdjustmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('LeaveEntitlementView');
    }

    public function view(User $user, LeaveAdjustment $adjustment): bool
    {
        return $user->hasPermissionTo('LeaveEntitlementView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('LeaveEntitlementUpdate');
    }

    /**
     * Nobody edits or deletes an adjustment, whatever permissions they hold.
     *
     * This is the whole reason adjustments are rows: "who gave me these three days
     * and when" must stay answerable, and an editable correction is a column with
     * extra steps. A wrong adjustment is corrected by a second adjustment in the
     * opposite direction, which leaves both facts and both reasons on the record.
     */
    public function update(User $user, LeaveAdjustment $adjustment): bool
    {
        return false;
    }

    public function delete(User $user, LeaveAdjustment $adjustment): bool
    {
        return false;
    }
}
