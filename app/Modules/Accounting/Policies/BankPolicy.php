<?php

namespace App\Modules\Accounting\Policies;

use App\Modules\Accounting\Models\Bank;
use App\Modules\Core\Models\User;

class BankPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('BankView');
    }

    public function view(User $user, Bank $bank): bool
    {
        return $user->hasPermissionTo('BankView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('BankCreate');
    }

    public function update(User $user, Bank $bank): bool
    {
        return $user->hasPermissionTo('BankUpdate');
    }

    public function delete(User $user, Bank $bank): bool
    {
        if (! $user->hasPermissionTo('BankDelete')) {
            return false;
        }

        // Banks with employees attached must not be deleted.
        return ! $bank->employees()->exists();
    }
}
