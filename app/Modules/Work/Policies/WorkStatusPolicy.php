<?php

namespace App\Modules\Work\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Work\Models\WorkStatus;

/**
 * Reading is every board user's; changing is WorkflowManage's.
 *
 * A status belongs to a workflow, so it is the workflow's permission: adding a column to one
 * board's workflow adds it to every board that workflow governs.
 */
class WorkStatusPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('WorkItemView');
    }

    public function view(User $user, WorkStatus $record): bool
    {
        return $user->hasPermissionTo('WorkItemView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('WorkflowManage');
    }

    public function update(User $user, WorkStatus $record): bool
    {
        return $user->hasPermissionTo('WorkflowManage');
    }

    public function delete(User $user, WorkStatus $record): bool
    {
        return $user->hasPermissionTo('WorkflowManage');
    }
}
