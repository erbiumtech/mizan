<?php

namespace App\Modules\ConstructionField\Policies;

use App\Modules\ConstructionField\Models\DailyLog;
use App\Modules\Core\Models\User;

/**
 * Who writes the diary, and who signs it off — §16.1.
 *
 * **Writing it is site's**, which needs no argument: the diary is a record of what happened on site, written by
 * somebody who was there. It is the fourth create grant site staff hold in this suite.
 *
 * **`ConstructionDailyLogApprove` is its own name, and §18.2 lists it** among the non-CRUD permissions that matter.
 * Approval locks the day and turns it into evidence, so it is not the same act as writing it — and the same grant
 * carries reopening, because whoever may sign a day off is who may unsign it.
 *
 * **There is no delete.** A day that happened cannot be made not to have happened, and a missing diary in a run of
 * dates is a question at adjudication. An empty day is recorded as empty.
 */
class DailyLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ConstructionDailyLogView');
    }

    public function view(User $user, DailyLog $log): bool
    {
        return $user->can('ConstructionDailyLogView');
    }

    public function create(User $user): bool
    {
        return $user->can('ConstructionDailyLogUpdate');
    }

    /** Editable until it is approved, which is the whole of §16.1's rule. */
    public function update(User $user, DailyLog $log): bool
    {
        return $user->can('ConstructionDailyLogUpdate') && ! $log->isApproved();
    }

    public function approve(User $user, DailyLog $log): bool
    {
        return $user->can('ConstructionDailyLogApprove') && ! $log->isApproved();
    }

    /** Whoever may sign a day off is who may unsign it — with a reason, which the service enforces. */
    public function reopen(User $user, DailyLog $log): bool
    {
        return $user->can('ConstructionDailyLogApprove') && $log->isApproved();
    }
}
