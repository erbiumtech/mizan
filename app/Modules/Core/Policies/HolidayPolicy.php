<?php

namespace App\Modules\Core\Policies;

use App\Modules\Core\Models\Holiday;
use App\Modules\Core\Models\User;

class HolidayPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('HolidayView');
    }

    public function view(User $user, Holiday $holiday): bool
    {
        return $user->hasPermissionTo('HolidayView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('HolidayCreate');
    }

    public function update(User $user, Holiday $holiday): bool
    {
        return $user->hasPermissionTo('HolidayUpdate');
    }

    public function delete(User $user, Holiday $holiday): bool
    {
        return $user->hasPermissionTo('HolidayDelete');
    }
}
