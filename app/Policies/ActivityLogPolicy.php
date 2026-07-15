<?php

namespace App\Policies;

use App\Models\User;
use Spatie\Activitylog\Models\Activity;

class ActivityLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('ActivityLogView');
    }

    public function view(User $user, Activity $activity): bool
    {
        return $user->hasPermissionTo('ActivityLogView');
    }

    // Audit logs are immutable: nobody can create, update, or delete them via the UI.
    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Activity $activity): bool
    {
        return false;
    }

    public function delete(User $user, Activity $activity): bool
    {
        return false;
    }
}
