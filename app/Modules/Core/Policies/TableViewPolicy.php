<?php

namespace App\Modules\Core\Policies;

use App\Modules\Core\Models\TableView;
use App\Modules\Core\Models\User;

/**
 * Users manage their own views; public/global views are viewable by company
 * members; only Administrators (per-company role) may create/modify global
 * views or manage others' views via the admin resource.
 */
class TableViewPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, TableView $view): bool
    {
        return $this->owns($user, $view) || $view->is_public || $view->is_global || $user->hasRole('Administrator');
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, TableView $view): bool
    {
        return $this->owns($user, $view) || $user->hasRole('Administrator');
    }

    public function delete(User $user, TableView $view): bool
    {
        return $this->owns($user, $view) || $user->hasRole('Administrator');
    }

    /** Only administrators may pin a view globally for the whole company. */
    public function setGlobal(User $user): bool
    {
        return $user->hasRole('Administrator');
    }

    protected function owns(User $user, TableView $view): bool
    {
        return $view->user_id === $user->getKey();
    }
}
