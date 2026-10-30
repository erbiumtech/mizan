<?php

namespace App\Modules\Work\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Work\Models\WorkWorkflowRule;

/**
 * Reading is every board user's; changing is WorkflowManage's.
 *
 * A rule is what a workflow refuses, so it is the workflow's permission. Everybody may see
 * why a move was refused; changing what gets refused is an administrator's.
 */
class WorkWorkflowRulePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('WorkItemView');
    }

    public function view(User $user, WorkWorkflowRule $record): bool
    {
        return $user->hasPermissionTo('WorkItemView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('WorkflowManage');
    }

    public function update(User $user, WorkWorkflowRule $record): bool
    {
        return $user->hasPermissionTo('WorkflowManage');
    }

    public function delete(User $user, WorkWorkflowRule $record): bool
    {
        return $user->hasPermissionTo('WorkflowManage');
    }
}
