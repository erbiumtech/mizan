<?php

namespace App\Modules\Performance\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Performance\Models\OneToOne;

/**
 * One-to-ones, and the one rule in this module that is not about permissions alone.
 *
 * `private_notes` is manager-and-above only and **never** readable by the person it is
 * about. That needs stating separately because EmployeeAccess grants a manager their whole
 * DOWNLINE: without a rule of its own, somebody inside their own manager's scope would be
 * able to read the notes written about them.
 *
 * The row-level answer is OneToOne::privateNotesVisibleTo(); this is the resource-level
 * gate. docs/hrms-plan.md §7.2.
 */
class OneToOnePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('ReviewView');
    }

    public function view(User $user, OneToOne $record): bool
    {
        return $user->hasPermissionTo('ReviewView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('ReviewCreate');
    }

    /** The manager who recorded it, or somebody privileged. Never the subject. */
    public function update(User $user, OneToOne $record): bool
    {
        return $user->hasPermissionTo('ReviewUpdate')
            && $record->employee?->user_id !== $user->getKey();
    }

    public function delete(User $user, OneToOne $record): bool
    {
        return $user->hasPermissionTo('ReviewDelete')
            && $record->employee?->user_id !== $user->getKey();
    }

    /** Whether this user may read the private half at all. */
    public function viewPrivateNotes(User $user, OneToOne $record): bool
    {
        return $record->privateNotesVisibleTo($user);
    }
}
