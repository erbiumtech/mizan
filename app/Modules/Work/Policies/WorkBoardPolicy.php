<?php

namespace App\Modules\Work\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Work\Models\WorkBoard;

/**
 * Reading is every board user's; changing is BoardManage's.
 *
 * A board is the shape of the work rather than the work itself: everybody reads one, and changing
 * its columns, lanes or limits is BoardManage's — see docs/project-management-plan.md §13.1.
 */
class WorkBoardPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('WorkItemView');
    }

    public function view(User $user, WorkBoard $record): bool
    {
        return $user->hasPermissionTo('WorkItemView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('BoardManage');
    }

    public function update(User $user, WorkBoard $record): bool
    {
        return $user->hasPermissionTo('BoardManage');
    }

    public function delete(User $user, WorkBoard $record): bool
    {
        return $user->hasPermissionTo('BoardManage');
    }
}
