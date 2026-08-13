<?php

namespace App\Modules\Attendance\Policies;

use App\Modules\Attendance\Models\AttendanceRegularization;
use App\Modules\Core\Models\User;

class AttendanceRegularizationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('AttendanceRegularizationView');
    }

    public function view(User $user, AttendanceRegularization $request): bool
    {
        return $user->hasPermissionTo('AttendanceRegularizationView');
    }

    /** Every employee may ask about their own past — that is what this table is for. */
    public function create(User $user): bool
    {
        return $user->hasPermissionTo('AttendanceRegularizationCreate');
    }

    public function update(User $user, AttendanceRegularization $request): bool
    {
        return $user->hasPermissionTo('AttendanceRegularizationCreate') && $request->isPending();
    }

    public function delete(User $user, AttendanceRegularization $request): bool
    {
        return $user->hasPermissionTo('AttendanceRegularizationApprove') && $request->isPending();
    }

    /**
     * Deciding is its own permission, and never about yourself.
     *
     * Unlike leave, there is no setting to waive this. A leave dead end is real — the
     * person at the top of the tree has nobody above them — but a correction to one's
     * own attendance record approved by oneself is just an edit with extra steps, and
     * nobody is blocked from working by refusing it.
     */
    public function decide(User $user, AttendanceRegularization $request): bool
    {
        return $user->hasPermissionTo(AttendanceRegularization::APPROVE_PERMISSION)
            && $request->isPending()
            && ! $request->belongsToUser($user);
    }
}
