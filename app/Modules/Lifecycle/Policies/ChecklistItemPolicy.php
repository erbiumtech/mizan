<?php

namespace App\Modules\Lifecycle\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Lifecycle\Models\ChecklistItem;

/**
 * Checklists share one permission group.
 *
 * Four models, one group: a template, its lines, a person's run through it and that
 * run's tasks are one feature, and nobody grants them separately. Eight more permission
 * names would be eight more rows in every role form for no decision anybody makes.
 */
class ChecklistItemPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('ChecklistView');
    }

    public function view(User $user, ChecklistItem $record): bool
    {
        return $user->hasPermissionTo('ChecklistView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('ChecklistCreate');
    }

    public function update(User $user, ChecklistItem $record): bool
    {
        return $user->hasPermissionTo('ChecklistUpdate');
    }

    public function delete(User $user, ChecklistItem $record): bool
    {
        return $user->hasPermissionTo('ChecklistDelete');
    }
}
