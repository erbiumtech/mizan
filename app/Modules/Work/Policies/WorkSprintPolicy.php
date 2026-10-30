<?php

namespace App\Modules\Work\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Work\Models\WorkSprint;

/**
 * Reading is every board user's; changing is SprintManage's.
 *
 * A board is the shape of the work rather than the work itself: everybody reads one, and changing
 * its columns, lanes or limits is SprintManage's — see docs/project-management-plan.md §13.1.
 */
class WorkSprintPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('WorkItemView');
    }

    public function view(User $user, WorkSprint $record): bool
    {
        return $user->hasPermissionTo('WorkItemView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('SprintManage');
    }

    public function update(User $user, WorkSprint $record): bool
    {
        return $user->hasPermissionTo('SprintManage');
    }

    public function delete(User $user, WorkSprint $record): bool
    {
        return $user->hasPermissionTo('SprintManage');
    }
}
