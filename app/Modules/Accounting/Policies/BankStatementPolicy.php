<?php

namespace App\Modules\Accounting\Policies;

use App\Modules\Accounting\Models\BankStatement;
use App\Modules\Core\Models\User;

class BankStatementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('BankStatementView');
    }

    public function view(User $user, BankStatement $statement): bool
    {
        return $user->hasPermissionTo('BankStatementView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('BankStatementCreate');
    }

    public function update(User $user, BankStatement $statement): bool
    {
        // Completed statements are locked.
        return $user->hasPermissionTo('BankStatementUpdate')
            && ! $statement->isCompleted();
    }

    public function delete(User $user, BankStatement $statement): bool
    {
        return $user->hasPermissionTo('BankStatementDelete')
            && ! $statement->isCompleted();
    }

    public function import(User $user, BankStatement $statement): bool
    {
        return $user->hasPermissionTo('BankStatementImport')
            && ! $statement->isCompleted();
    }

    public function match(User $user, BankStatement $statement): bool
    {
        return $user->hasPermissionTo('BankStatementMatch')
            && ! $statement->isCompleted();
    }

    public function complete(User $user, BankStatement $statement): bool
    {
        return $user->hasPermissionTo('BankStatementComplete')
            && ! $statement->isCompleted();
    }
}
