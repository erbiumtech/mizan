<?php

namespace App\Modules\Crm\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Crm\Models\NextAction;

/**
 * Activities and next actions share one group with the deals they hang off.
 *
 * Logging a call and moving a deal are the same job, and a role that could see a pipeline
 * but not its call history would be looking at a board with the reasoning removed.
 */
class NextActionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('OpportunityView');
    }

    public function view(User $user, NextAction $record): bool
    {
        return $user->hasPermissionTo('OpportunityView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('OpportunityUpdate');
    }

    public function update(User $user, NextAction $record): bool
    {
        return $user->hasPermissionTo('OpportunityUpdate');
    }

    public function delete(User $user, NextAction $record): bool
    {
        return $user->hasPermissionTo('OpportunityUpdate');
    }
}
