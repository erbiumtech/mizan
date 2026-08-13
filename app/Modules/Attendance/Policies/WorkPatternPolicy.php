<?php

namespace App\Modules\Attendance\Policies;

use App\Modules\Attendance\Models\WorkPattern;
use App\Modules\Core\Models\User;

class WorkPatternPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('WorkPatternView');
    }

    public function view(User $user, WorkPattern $pattern): bool
    {
        return $user->hasPermissionTo('WorkPatternView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('WorkPatternCreate');
    }

    public function update(User $user, WorkPattern $pattern): bool
    {
        return $user->hasPermissionTo('WorkPatternUpdate');
    }

    /**
     * A pattern anybody has been assigned to stays.
     *
     * The assignment restricts on delete, and beyond that: deleting a pattern
     * retrospectively changes which past days were working days, which changes what
     * every historical month means. Deactivate by reassigning people instead.
     */
    public function delete(User $user, WorkPattern $pattern): bool
    {
        return $user->hasPermissionTo('WorkPatternDelete') && ! $pattern->assignments()->exists();
    }
}
