<?php

namespace App\Modules\Core\Policies;

use App\Modules\Core\Models\ReportSchedule;
use App\Modules\Core\Models\User;

/**
 * Who may keep a schedule — `docs/reports-expansion-plan.md` Phase 8, items 1 and 2.
 *
 * **Reading the list is a permission; changing a schedule is a permission *and* ownership.** A schedule is not
 * a document, it is a standing instruction rendered with its owner's access — so somebody else editing its
 * recipients would be sending that owner's rows to a list the owner never agreed to. Item 2 names the owner as
 * the whole of the security model, and this is that sentence as a policy.
 *
 * An Administrator is the exception, because somebody has to be able to switch off a schedule whose owner is
 * on leave — and an Administrator holds every permission in the company anyway, so the alternative would be a
 * support request that ends in a database edit.
 */
class ReportSchedulePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('ReportScheduleView');
    }

    public function view(User $user, ReportSchedule $schedule): bool
    {
        return $user->hasPermissionTo('ReportScheduleView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('ReportScheduleCreate');
    }

    public function update(User $user, ReportSchedule $schedule): bool
    {
        return $user->hasPermissionTo('ReportScheduleUpdate') && $this->owns($user, $schedule);
    }

    public function delete(User $user, ReportSchedule $schedule): bool
    {
        return $user->hasPermissionTo('ReportScheduleDelete') && $this->owns($user, $schedule);
    }

    /** Theirs, or an Administrator's to stop. */
    private function owns(User $user, ReportSchedule $schedule): bool
    {
        return (int) $schedule->user_id === (int) $user->getKey() || $user->isAdministrator();
    }
}
