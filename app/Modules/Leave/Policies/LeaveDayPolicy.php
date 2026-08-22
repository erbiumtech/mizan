<?php

namespace App\Modules\Leave\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Leave\Models\LeaveDay;

/**
 * Leave days are generated, never entered.
 *
 * They exist so the policy is registered — ModuleCoverageTest asserts every model has
 * one, and a model with no policy is a model Filament treats as allowed. Reading them
 * follows the request they belong to; writing them is LeaveDayGenerator's job and
 * nobody else's, because a hand-edited day is a balance charge with no request behind
 * it.
 */
class LeaveDayPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('LeaveRequestView');
    }

    public function view(User $user, LeaveDay $day): bool
    {
        return $user->hasPermissionTo('LeaveRequestView');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, LeaveDay $day): bool
    {
        return false;
    }

    public function delete(User $user, LeaveDay $day): bool
    {
        return false;
    }
}
