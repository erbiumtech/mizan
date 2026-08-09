<?php

namespace App\Modules\Accounting\Policies;

use App\Modules\Accounting\Models\Beneficiary;
use App\Modules\Core\Models\User;

class BeneficiaryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('BeneficiaryView');
    }

    public function view(User $user, Beneficiary $beneficiary): bool
    {
        return $user->hasPermissionTo('BeneficiaryView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('BeneficiaryCreate');
    }

    public function update(User $user, Beneficiary $beneficiary): bool
    {
        return $user->hasPermissionTo('BeneficiaryUpdate');
    }

    public function delete(User $user, Beneficiary $beneficiary): bool
    {
        if (! $user->hasPermissionTo('BeneficiaryDelete')) {
            return false;
        }

        return ! $beneficiary->payments()->exists();
    }
}
