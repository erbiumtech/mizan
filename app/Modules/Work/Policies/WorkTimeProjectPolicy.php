<?php

namespace App\Modules\Work\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Work\Models\WorkTimeProject;

/**
 * Reading is `IntegrationManage`'s; changing is `IntegrationManage`'s — docs/project-management-plan.md §13.1.
 */
class WorkTimeProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('IntegrationManage');
    }

    public function view(User $user, WorkTimeProject $record): bool
    {
        return $user->hasPermissionTo('IntegrationManage');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('IntegrationManage');
    }

    public function update(User $user, WorkTimeProject $record): bool
    {
        return $user->hasPermissionTo('IntegrationManage');
    }

    public function delete(User $user, WorkTimeProject $record): bool
    {
        return $user->hasPermissionTo('IntegrationManage');
    }
}
