<?php

namespace App\Modules\Performance\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Performance\Models\MeritEntry;

/** Read-only, by design: the ledger is written by the things that happen, not by hand. */
class MeritEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('MeritView');
    }

    public function view(User $user, MeritEntry $entry): bool
    {
        return $user->hasPermissionTo('MeritView');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, MeritEntry $entry): bool
    {
        return false;
    }

    public function delete(User $user, MeritEntry $entry): bool
    {
        return false;
    }
}
