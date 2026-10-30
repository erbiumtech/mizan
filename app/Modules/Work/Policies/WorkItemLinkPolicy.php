<?php

namespace App\Modules\Work\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Work\Models\WorkItemLink;

/** Linking two items is editing both of them, so it is the item permission rather than one of its own. */
class WorkItemLinkPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('WorkItemView');
    }

    public function view(User $user, WorkItemLink $record): bool
    {
        return $user->hasPermissionTo('WorkItemView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('WorkItemUpdate');
    }

    public function update(User $user, WorkItemLink $record): bool
    {
        return $user->hasPermissionTo('WorkItemUpdate');
    }

    public function delete(User $user, WorkItemLink $record): bool
    {
        return $user->hasPermissionTo('WorkItemUpdate');
    }
}
