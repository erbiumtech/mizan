<?php

namespace App\Modules\Devices\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Devices\Models\AttendanceDevice;

/** Devices are managed from Company Settings; punches are read by the folding job. Administrators only. */
class AttendanceDevicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdministrator();
    }

    public function view(User $user, AttendanceDevice $record): bool
    {
        return $user->isAdministrator();
    }

    public function create(User $user): bool
    {
        return $user->isAdministrator();
    }

    public function update(User $user, AttendanceDevice $record): bool
    {
        return $user->isAdministrator();
    }

    public function delete(User $user, AttendanceDevice $record): bool
    {
        return $user->isAdministrator();
    }
}
