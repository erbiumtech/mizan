<?php

namespace App\Modules\Work\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Work\Models\WorkRelease;

/**
 * Reading is every board user's; changing is ReleaseManage's.
 *
 * Marking a release as shipped is a statement to customers as much as to the team, which is why it
 * sits with the Manager's grants rather than the Employee's — see docs/project-management-plan.md §13.1.
 */
class WorkReleasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('WorkItemView');
    }

    public function view(User $user, WorkRelease $record): bool
    {
        return $user->hasPermissionTo('WorkItemView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('ReleaseManage');
    }

    public function update(User $user, WorkRelease $record): bool
    {
        return $user->hasPermissionTo('ReleaseManage');
    }

    public function delete(User $user, WorkRelease $record): bool
    {
        return $user->hasPermissionTo('ReleaseManage');
    }
}
