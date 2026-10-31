<?php

namespace App\Modules\Work\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Work\Models\WorkItemCommit;

/**
 * Reading is `WorkItemView`'s; changing is `IntegrationManage`'s.
 *
 * An integration holds a credential to somebody else's system, which is why managing one is the
 * administrator's and not the board's — docs/project-management-plan.md §13.1.
 */
class WorkItemCommitPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('WorkItemView');
    }

    public function view(User $user, WorkItemCommit $record): bool
    {
        return $user->hasPermissionTo('WorkItemView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('IntegrationManage');
    }

    public function update(User $user, WorkItemCommit $record): bool
    {
        return $user->hasPermissionTo('IntegrationManage');
    }

    public function delete(User $user, WorkItemCommit $record): bool
    {
        return $user->hasPermissionTo('IntegrationManage');
    }
}
