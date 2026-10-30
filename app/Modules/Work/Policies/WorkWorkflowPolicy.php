<?php

namespace App\Modules\Work\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Work\Models\WorkWorkflow;

/**
 * Reading is every board user's; changing is WorkflowManage's.
 *
 * A workflow governs every board in the company, which is why changing one is not a
 * board manager's job but an administrator's — see docs/project-management-plan.md §13.1.
 */
class WorkWorkflowPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('WorkItemView');
    }

    public function view(User $user, WorkWorkflow $record): bool
    {
        return $user->hasPermissionTo('WorkItemView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('WorkflowManage');
    }

    public function update(User $user, WorkWorkflow $record): bool
    {
        return $user->hasPermissionTo('WorkflowManage');
    }

    public function delete(User $user, WorkWorkflow $record): bool
    {
        return $user->hasPermissionTo('WorkflowManage');
    }
}
