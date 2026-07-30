<?php

namespace App\Modules\Accounting\Policies;

use App\Modules\Accounting\Models\CompanyBankAccount;
use App\Modules\Core\Models\User;

class CompanyBankAccountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('CompanyBankAccountView');
    }

    public function view(User $user, CompanyBankAccount $companyBankAccount): bool
    {
        return $user->hasPermissionTo('CompanyBankAccountView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('CompanyBankAccountCreate');
    }

    public function update(User $user, CompanyBankAccount $companyBankAccount): bool
    {
        return $user->hasPermissionTo('CompanyBankAccountUpdate');
    }

    public function delete(User $user, CompanyBankAccount $companyBankAccount): bool
    {
        if (! $user->hasPermissionTo('CompanyBankAccountDelete')) {
            return false;
        }

        return ! $companyBankAccount->payments()->exists();
    }
}
