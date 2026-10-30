<?php

namespace App\Modules\Work\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Work\Models\WorkLabel;

/**
 * Reading is every board user's; changing is BoardManage's.
 *
 * A label is the shape of the board rather than the work on it, so it is a board manager's
 * to create and everybody's to put on a card.
 */
class WorkLabelPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('WorkItemView');
    }

    public function view(User $user, WorkLabel $record): bool
    {
        return $user->hasPermissionTo('WorkItemView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('BoardManage');
    }

    public function update(User $user, WorkLabel $record): bool
    {
        return $user->hasPermissionTo('BoardManage');
    }

    public function delete(User $user, WorkLabel $record): bool
    {
        return $user->hasPermissionTo('BoardManage');
    }
}
