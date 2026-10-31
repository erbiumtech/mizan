<?php

namespace App\Modules\Work\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Work\Models\IntegrationEvent;

/**
 * Reading is `IntegrationManage`'s; changing is `IntegrationManage`'s.
 *
 * An integration holds a credential to somebody else's system, which is why managing one is the
 * administrator's and not the board's — docs/project-management-plan.md §13.1.
 */
class IntegrationEventPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('IntegrationManage');
    }

    public function view(User $user, IntegrationEvent $record): bool
    {
        return $user->hasPermissionTo('IntegrationManage');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('IntegrationManage');
    }

    public function update(User $user, IntegrationEvent $record): bool
    {
        return $user->hasPermissionTo('IntegrationManage');
    }

    public function delete(User $user, IntegrationEvent $record): bool
    {
        return $user->hasPermissionTo('IntegrationManage');
    }
}
