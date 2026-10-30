<?php

namespace App\Modules\Work\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Work\Models\WorkAttachment;

/**
 * A file on an item, which anybody working the board may add — and only its author or somebody who
 * may edit items may remove.
 *
 * Deleting is the one that differs: an attachment is evidence on a record, and letting whoever
 * walks past remove somebody else's screenshot of the bug is how a thread stops being a record.
 */
class WorkAttachmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('WorkItemView');
    }

    public function view(User $user, WorkAttachment $record): bool
    {
        return $user->hasPermissionTo('WorkItemView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('WorkItemCreate');
    }

    public function update(User $user, WorkAttachment $record): bool
    {
        return $record->isOwnedBy($user) || $user->hasPermissionTo('WorkItemUpdate');
    }

    public function delete(User $user, WorkAttachment $record): bool
    {
        return $record->isOwnedBy($user) || $user->hasPermissionTo('WorkItemUpdate');
    }
}
