<?php

namespace App\Modules\Crm\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Crm\Models\OpportunityStageHistory;

/**
 * Stage history is written by the service and read by reports. Nobody edits it.
 *
 * It exists so the policy is registered — ModuleCoverageTest asserts every model has one, and
 * a model with no policy is one Filament treats as allowed. Every velocity figure is derived
 * from these rows, so a hand-edited one is a report that quietly disagrees with what
 * happened.
 */
class OpportunityStageHistoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('OpportunityView');
    }

    public function view(User $user, OpportunityStageHistory $record): bool
    {
        return $user->hasPermissionTo('OpportunityView');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, OpportunityStageHistory $record): bool
    {
        return false;
    }

    public function delete(User $user, OpportunityStageHistory $record): bool
    {
        return false;
    }
}
