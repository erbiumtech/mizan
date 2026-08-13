<?php

namespace App\Modules\Crm\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Crm\Models\Activity;

/**
 * Activities and next actions share one group with the deals they hang off.
 *
 * Logging a call and moving a deal are the same job, and a role that could see a pipeline
 * but not its call history would be looking at a board with the reasoning removed.
 */
class ActivityPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('OpportunityView');
    }

    public function view(User $user, Activity $record): bool
    {
        return $user->hasPermissionTo('OpportunityView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('OpportunityUpdate');
    }

    /**
     * History is not edited.
     *
     * A call that happened at 14:20 happened at 14:20. Correcting the record means logging
     * the correction, which is what keeps a timeline trustworthy — and it is the difference
     * between an activity and a comment, which IS editable and exists on the same records.
     */
    public function update(User $user, Activity $record): bool
    {
        return false;
    }

    /** Deleting is for a mis-keyed entry, so it stays with whoever may write them. */
    public function delete(User $user, Activity $record): bool
    {
        return $user->hasPermissionTo('OpportunityUpdate');
    }
}
