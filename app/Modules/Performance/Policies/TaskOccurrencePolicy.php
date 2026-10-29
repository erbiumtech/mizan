<?php

namespace App\Modules\Performance\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Performance\Models\TaskOccurrence;

/**
 * Seeing is `TaskView` over the rows the resource scopes to you — your own and
 * your downline's. Completing is yours when the task is yours; excusing is a
 * permission of its own, because it reverses a ledger entry.
 */
class TaskOccurrencePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('TaskView');
    }

    public function view(User $user, TaskOccurrence $occurrence): bool
    {
        return $user->hasPermissionTo('TaskView');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, TaskOccurrence $occurrence): bool
    {
        return $user->hasPermissionTo('TaskUpdate');
    }

    public function complete(User $user, TaskOccurrence $occurrence): bool
    {
        return $occurrence->employee?->user_id === $user->getKey() || $user->hasPermissionTo('TaskUpdate');
    }

    public function excuse(User $user, TaskOccurrence $occurrence): bool
    {
        return $user->hasPermissionTo('TaskExcuse');
    }

    public function delete(User $user, TaskOccurrence $occurrence): bool
    {
        return false;
    }
}
