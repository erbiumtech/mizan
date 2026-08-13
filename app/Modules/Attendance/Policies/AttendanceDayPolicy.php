<?php

namespace App\Modules\Attendance\Policies;

use App\Modules\Attendance\Models\AttendanceDay;
use App\Modules\Core\Models\User;

class AttendanceDayPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('AttendanceView');
    }

    public function view(User $user, AttendanceDay $day): bool
    {
        return $user->hasPermissionTo('AttendanceView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('AttendanceCreate');
    }

    /**
     * A day covered by approved leave is not editable here.
     *
     * The recorder refuses the write anyway, but a policy that lets somebody press a
     * button the service will refuse produces an error where an absent button would
     * have explained itself.
     */
    public function update(User $user, AttendanceDay $day): bool
    {
        return $user->hasPermissionTo('AttendanceUpdate') && ! $day->isCoveredByLeave();
    }

    public function delete(User $user, AttendanceDay $day): bool
    {
        return $user->hasPermissionTo('AttendanceDelete') && ! $day->isCoveredByLeave();
    }
}
