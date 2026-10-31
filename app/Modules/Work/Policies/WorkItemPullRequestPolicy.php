<?php

namespace App\Modules\Work\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Work\Models\WorkItemPullRequest;

/**
 * Reading is `WorkItemView`'s; changing is `WorkItemUpdate`'s.
 *
 * An integration holds a credential to somebody else's system, which is why managing one is the
 * administrator's and not the board's — docs/project-management-plan.md §13.1.
 */
class WorkItemPullRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('WorkItemView');
    }

    public function view(User $user, WorkItemPullRequest $record): bool
    {
        return $user->hasPermissionTo('WorkItemView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('WorkItemUpdate');
    }

    public function update(User $user, WorkItemPullRequest $record): bool
    {
        return $user->hasPermissionTo('WorkItemUpdate');
    }

    public function delete(User $user, WorkItemPullRequest $record): bool
    {
        return $user->hasPermissionTo('WorkItemUpdate');
    }
}
