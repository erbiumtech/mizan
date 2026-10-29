<?php

namespace App\Modules\Accounting\Policies;

use App\Modules\Accounting\Models\ChequeLayout;
use App\Modules\Core\Models\User;

class ChequeLayoutPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('ChequeView');
    }

    public function view(User $user, ChequeLayout $chequeLayout): bool
    {
        return $user->hasPermissionTo('ChequeView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('ChequeCreate');
    }

    public function update(User $user, ChequeLayout $chequeLayout): bool
    {
        return $user->hasPermissionTo('ChequeUpdate');
    }

    public function delete(User $user, ChequeLayout $chequeLayout): bool
    {
        return $user->hasPermissionTo('ChequeDelete');
    }
}
