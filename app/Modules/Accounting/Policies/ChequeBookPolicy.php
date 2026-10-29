<?php

namespace App\Modules\Accounting\Policies;

use App\Modules\Accounting\Models\ChequeBook;
use App\Modules\Core\Models\User;

class ChequeBookPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('ChequeView');
    }

    public function view(User $user, ChequeBook $chequeBook): bool
    {
        return $user->hasPermissionTo('ChequeView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('ChequeCreate');
    }

    public function update(User $user, ChequeBook $chequeBook): bool
    {
        return $user->hasPermissionTo('ChequeUpdate');
    }

    public function delete(User $user, ChequeBook $chequeBook): bool
    {
        return $user->hasPermissionTo('ChequeDelete') && ! $chequeBook->cheques()->exists();
    }
}
