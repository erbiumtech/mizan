<?php

namespace App\Modules\Devices\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Devices\Models\DevicePunch;

/** Devices are managed from Company Settings; punches are read by the folding job. Administrators only. */
class DevicePunchPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdministrator();
    }

    public function view(User $user, DevicePunch $record): bool
    {
        return $user->isAdministrator();
    }

    public function create(User $user): bool
    {
        return $user->isAdministrator();
    }

    public function update(User $user, DevicePunch $record): bool
    {
        return $user->isAdministrator();
    }

    public function delete(User $user, DevicePunch $record): bool
    {
        return $user->isAdministrator();
    }
}
