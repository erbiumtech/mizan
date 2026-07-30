<?php

namespace App\Modules\Accounting\Policies;

use App\Modules\Accounting\Models\TransactionType;
use App\Modules\Core\Models\User;

class TransactionTypePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('TransactionTypeView');
    }

    public function view(User $user, TransactionType $transactionType): bool
    {
        return $user->hasPermissionTo('TransactionTypeView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('TransactionTypeCreate');
    }

    public function update(User $user, TransactionType $transactionType): bool
    {
        return $user->hasPermissionTo('TransactionTypeUpdate');
    }

    public function delete(User $user, TransactionType $transactionType): bool
    {
        if (! $user->hasPermissionTo('TransactionTypeDelete')) {
            return false;
        }

        return ! $transactionType->payments()->exists() && ! $transactionType->journalEntries()->exists();
    }
}
