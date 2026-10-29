<?php

namespace App\Modules\Lifecycle\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Lifecycle\Models\Separation;

/** Travels with the Settlement permissions — docs/saas-launch-plan.md §14.5. */
class SeparationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('SettlementView');
    }

    public function view(User $user, Separation $row): bool
    {
        return $user->hasPermissionTo('SettlementView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('SettlementCreate');
    }

    public function update(User $user, Separation $row): bool
    {
        return $user->hasPermissionTo('SettlementUpdate');
    }

    public function delete(User $user, Separation $row): bool
    {
        return $user->hasPermissionTo('SettlementDelete');
    }
}
