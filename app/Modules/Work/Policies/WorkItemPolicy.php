<?php

namespace App\Modules\Work\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Work\Models\WorkItem;

/**
 * Who may work the board — docs/project-management-plan.md §13.1 and §13.2.
 *
 * **Moving is its own permission.** A board shared with a client contact, or with a department that
 * watches progress without doing the work, needs somebody who may read every card and drag none of
 * them; `WorkItemUpdate` would give them the form, which is the same thing with more steps.
 */
class WorkItemPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('WorkItemView');
    }

    public function view(User $user, WorkItem $record): bool
    {
        return $user->hasPermissionTo('WorkItemView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('WorkItemCreate');
    }

    public function update(User $user, WorkItem $record): bool
    {
        return $user->hasPermissionTo('WorkItemUpdate');
    }

    /** Dragging a card, which `moveTo()` asks about before it asks the workflow. */
    public function move(User $user, WorkItem $record): bool
    {
        return $user->hasPermissionTo('WorkItemMove');
    }

    public function delete(User $user, WorkItem $record): bool
    {
        return $user->hasPermissionTo('WorkItemDelete');
    }
}
