<?php

namespace App\Policies;

use App\Models\BankStatementLine;
use App\Models\User;

class BankStatementLinePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('BankStatementView');
    }

    public function view(User $user, BankStatementLine $line): bool
    {
        return $user->hasPermissionTo('BankStatementView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('BankStatementImport');
    }

    public function update(User $user, BankStatementLine $line): bool
    {
        return $user->hasPermissionTo('BankStatementMatch')
            && ! $line->bankStatement->isCompleted();
    }

    public function delete(User $user, BankStatementLine $line): bool
    {
        return $user->hasPermissionTo('BankStatementImport')
            && ! $line->bankStatement->isCompleted();
    }

    /**
     * Manually match, unmatch or exclude a line.
     */
    public function match(User $user, BankStatementLine $line): bool
    {
        return $user->hasPermissionTo('BankStatementMatch')
            && ! $line->bankStatement->isCompleted();
    }
}
