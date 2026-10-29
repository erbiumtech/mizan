<?php

namespace App\Modules\Accounting\Policies;

use App\Modules\Accounting\Models\Cheque;
use App\Modules\Core\Models\User;

class ChequePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('ChequeView');
    }

    public function view(User $user, Cheque $cheque): bool
    {
        return $user->hasPermissionTo('ChequeView');
    }

    /** A cheque comes from a payment, never from a form. */
    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Cheque $cheque): bool
    {
        return $user->hasPermissionTo('ChequeUpdate');
    }

    /** A printed number is a record; it is voided, never deleted. */
    public function delete(User $user, Cheque $cheque): bool
    {
        return false;
    }
}
