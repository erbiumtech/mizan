<?php

namespace App\Modules\ConstructionCosting\Policies;

use App\Modules\ConstructionCosting\Models\Worker;
use App\Modules\Core\Models\User;

/**
 * Who maintains the worker register — `docs/construction-management-plan.md` §7.1.
 *
 * **Filing a worker is not the same decision as setting what an hour of their time costs**, which is why this reads
 * `ConstructionLabourUpdate` and the rate register reads `ConstructionLabourRateSet`. A ganger adds the six men who
 * turned up on Monday; what the company pays for an hour is a commercial decision that lands on every job.
 *
 * **There is no delete.** A worker with cost against their name is a row somebody will ask about when a week's hours
 * are disputed — `ended_on` records leaving, and `is_active` takes them off the pickers.
 */
class WorkerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ConstructionLabourView');
    }

    public function view(User $user, Worker $worker): bool
    {
        return $user->can('ConstructionLabourView');
    }

    public function create(User $user): bool
    {
        return $user->can('ConstructionLabourUpdate');
    }

    public function update(User $user, Worker $worker): bool
    {
        return $user->can('ConstructionLabourUpdate');
    }
}
