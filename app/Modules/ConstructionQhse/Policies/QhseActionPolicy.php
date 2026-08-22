<?php

namespace App\Modules\ConstructionQhse\Policies;

use App\Modules\ConstructionQhse\Models\QhseAction;
use App\Modules\Core\Models\User;

/**
 * Who raises an action, who completes it, and who verifies it — §17.4.
 *
 * **`ConstructionActionVerify` is its own permission**, and it is the same argument §16.4 makes about a passed
 * re-inspection: "done" is the assignee's claim and "verified" is somebody else's confirmation. If the two were one
 * grant, whoever caused a finding could close it — and an actions register nobody believes is a register nobody reads.
 *
 * Raising and completing are one grant. On a real site the person who writes the action down and the person who reports
 * it done are frequently the same, and splitting them would leave completed work showing as outstanding for want of a
 * second click.
 */
class QhseActionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ConstructionActionView');
    }

    public function view(User $user, QhseAction $action): bool
    {
        return $user->can('ConstructionActionView');
    }

    public function create(User $user): bool
    {
        return $user->can('ConstructionActionUpdate');
    }

    public function update(User $user, QhseAction $action): bool
    {
        return $user->can('ConstructionActionUpdate') && $action->isLive();
    }

    public function complete(User $user, QhseAction $action): bool
    {
        return $user->can('ConstructionActionUpdate') && $action->isLive();
    }

    /** Somebody else's confirmation. See the class docblock. */
    public function verify(User $user, QhseAction $action): bool
    {
        return $user->can('ConstructionActionVerify') && $action->isDone() && ! $action->isVerified();
    }

    public function cancel(User $user, QhseAction $action): bool
    {
        return $user->can('ConstructionActionUpdate') && $action->isLive();
    }

    /** No delete: an action raised against a finding is part of that finding's record. */
    public function delete(User $user, QhseAction $action): bool
    {
        return false;
    }
}
