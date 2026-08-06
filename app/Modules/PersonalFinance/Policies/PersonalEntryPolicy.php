<?php

namespace App\Modules\PersonalFinance\Policies;

use App\Modules\Core\Models\User;
use App\Modules\PersonalFinance\Models\PersonalEntry;

/**
 * Permission checks only. This policy is NOT what keeps one person's records
 * private — Gate::before in AppServiceProvider returns true for every ability
 * but `create` when the user is an Administrator or super admin, so an
 * ownership test here would never run for them. Ownership is enforced by the
 * BelongsToOwner global scope and its save/delete guard.
 */
class PersonalEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('PersonalFinanceView');
    }

    public function view(User $user, PersonalEntry $record): bool
    {
        return $user->hasPermissionTo('PersonalFinanceView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('PersonalFinanceCreate');
    }

    public function update(User $user, PersonalEntry $record): bool
    {
        return $user->hasPermissionTo('PersonalFinanceUpdate');
    }

    public function delete(User $user, PersonalEntry $record): bool
    {
        return $user->hasPermissionTo('PersonalFinanceDelete');
    }
}
