<?php

namespace App\Modules\Work\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Work\Models\WorkChannelSubscription;

/**
 * Reading is every board user's; changing is `BoardManage`'s — a channel is part of how a
 * project's board is presented, and a webhook URL is something a manager is trusted with.
 */
class WorkChannelSubscriptionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('WorkItemView');
    }

    public function view(User $user, WorkChannelSubscription $record): bool
    {
        return $user->hasPermissionTo('WorkItemView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('BoardManage');
    }

    public function update(User $user, WorkChannelSubscription $record): bool
    {
        return $user->hasPermissionTo('BoardManage');
    }

    public function delete(User $user, WorkChannelSubscription $record): bool
    {
        return $user->hasPermissionTo('BoardManage');
    }
}
