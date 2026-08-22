<?php

namespace App\Modules\Timesheets\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Timesheets\Models\TimesheetEntry;

class TimesheetEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('TimesheetView');
    }

    public function view(User $user, TimesheetEntry $entry): bool
    {
        return $user->hasPermissionTo('TimesheetView');
    }

    /** Booking your own time is the ordinary case, so every employee may. */
    public function create(User $user): bool
    {
        return $user->hasPermissionTo('TimesheetCreate');
    }

    /**
     * Not once billed.
     *
     * A client has paid for that hour; changing it afterwards makes the invoice
     * unreproducible. Approved-but-unbilled is still editable — that is a correction
     * before the money moves, which is exactly when corrections should happen.
     */
    public function update(User $user, TimesheetEntry $entry): bool
    {
        return $user->hasPermissionTo('TimesheetCreate') && ! $entry->isLocked();
    }

    public function delete(User $user, TimesheetEntry $entry): bool
    {
        return $user->hasPermissionTo('TimesheetCreate') && ! $entry->isLocked();
    }

    /** Approving is somebody else's job, and its own permission. */
    public function approve(User $user, TimesheetEntry $entry): bool
    {
        return $user->hasPermissionTo('TimesheetApprove') && ! $entry->isLocked();
    }
}
