<?php

namespace App\Modules\Performance\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Performance\Models\RecurringTask;

class RecurringTaskPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('TaskCreate');
    }

    public function view(User $user, RecurringTask $task): bool
    {
        return $user->hasPermissionTo('TaskCreate');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('TaskCreate');
    }

    public function update(User $user, RecurringTask $task): bool
    {
        return $user->hasPermissionTo('TaskUpdate');
    }

    public function delete(User $user, RecurringTask $task): bool
    {
        return $user->hasPermissionTo('TaskDelete');
    }
}
