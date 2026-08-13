<?php

namespace App\Modules\Crm\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Crm\Models\SalesTarget;

/**
 * Targets are set by management, and read by the person they are set for.
 *
 * Its own group rather than sharing Opportunity's: a salesperson works deals and should not
 * be able to edit the number they are measured against.
 */
class SalesTargetPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('SalesTargetView');
    }

    public function view(User $user, SalesTarget $target): bool
    {
        return $user->hasPermissionTo('SalesTargetView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('SalesTargetUpdate');
    }

    public function update(User $user, SalesTarget $target): bool
    {
        return $user->hasPermissionTo('SalesTargetUpdate');
    }

    public function delete(User $user, SalesTarget $target): bool
    {
        return $user->hasPermissionTo('SalesTargetUpdate');
    }
}
