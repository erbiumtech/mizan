<?php

namespace App\Policies;

use App\Models\Account;
use App\Models\User;

class AccountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('AccountView');
    }

    public function view(User $user, Account $account): bool
    {
        return $user->hasPermissionTo('AccountView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('AccountCreate');
    }

    public function update(User $user, Account $account): bool
    {
        return $user->hasPermissionTo('AccountUpdate');
    }

    public function delete(User $user, Account $account): bool
    {
        if (! $user->hasPermissionTo('AccountDelete')) {
            return false;
        }

        // Accounts with ledger history or children must not be deleted.
        return ! $account->lines()->exists() && ! $account->children()->exists();
    }
}
