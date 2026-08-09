<?php

namespace App\Modules\PersonalFinance\Policies;

use App\Modules\Core\Models\User;
use App\Modules\PersonalFinance\Models\TaxSchedule;

/**
 * Tax schedules are reference data, not personal data — the brackets are the
 * same for everybody, so this is the one model in the module with no owner.
 *
 * Anyone who can use the module may read them; only an Administrator may change
 * them, because editing a bracket changes what every person in the company is
 * told they owe. Note the Administrator check is the Gate::before bypass doing
 * the work rather than a permission, which is why the write abilities here look
 * unreachable — an ordinary user genuinely cannot reach them.
 */
class TaxSchedulePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('PersonalFinanceView');
    }

    public function view(User $user, TaxSchedule $schedule): bool
    {
        return $user->hasPermissionTo('PersonalFinanceView');
    }

    public function create(User $user): bool
    {
        return $user->isAdministrator();
    }

    public function update(User $user, TaxSchedule $schedule): bool
    {
        return $user->isAdministrator();
    }

    public function delete(User $user, TaxSchedule $schedule): bool
    {
        return $user->isAdministrator();
    }
}
