<?php

namespace App\Modules\Employees\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Announcement;

/**
 * Who manages announcements. Reading them needs no permission: the dashboard
 * widget shows every employee what was aimed at them.
 */
class AnnouncementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('AnnouncementView');
    }

    public function view(User $user, Announcement $announcement): bool
    {
        return $user->hasPermissionTo('AnnouncementView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('AnnouncementCreate');
    }

    public function update(User $user, Announcement $announcement): bool
    {
        return $user->hasPermissionTo('AnnouncementUpdate');
    }

    public function delete(User $user, Announcement $announcement): bool
    {
        return $user->hasPermissionTo('AnnouncementDelete');
    }
}
