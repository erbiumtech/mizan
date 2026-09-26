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

    /**
     * Share a view with everyone in the company (`is_public`).
     *
     * ponytail: a permission gate, not an approval workflow — Administrators hold
     * it by default and can hand it to trusted roles. Add a review queue (pending
     * flag + approve action) only if shared-view abuse actually appears.
     */
    public function publish(User $user): bool
    {
        return $user->hasPermissionTo('TableViewPublish');
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
