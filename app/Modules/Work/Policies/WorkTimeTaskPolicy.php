<?php

namespace App\Modules\Work\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Work\Models\WorkTimeTask;

/**
 * Reading is `WorkItemView`'s; changing is `WorkItemUpdate`'s — docs/project-management-plan.md §13.1.
 */
class WorkTimeTaskPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('WorkItemView');
    }

    public function view(User $user, WorkTimeTask $record): bool
    {
        return $user->hasPermissionTo('WorkItemView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('WorkItemUpdate');
    }

    public function update(User $user, WorkTimeTask $record): bool
    {
        return $user->hasPermissionTo('WorkItemUpdate');
    }

    public function delete(User $user, WorkTimeTask $record): bool
    {
        return $user->hasPermissionTo('WorkItemUpdate');
    }
}
