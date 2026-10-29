<?php

namespace App\Modules\Core\Policies;

use App\Modules\Core\Models\MessageLog;
use App\Modules\Core\Models\User;

/**
 * Read-only for everybody, and only for whoever holds `MessageLogView`.
 *
 * The log is every address and phone number the company has written to, on one
 * screen with a search box. That is a narrower thing to hand out than it looks,
 * which is why no seeded role but Administrator starts with it.
 */
class MessageLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('MessageLogView');
    }

    public function view(User $user, MessageLog $log): bool
    {
        return $user->can('MessageLogView');
    }

    /** Written by the application, never by a person, and never edited after. */
    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, MessageLog $log): bool
    {
        return false;
    }

    public function delete(User $user, MessageLog $log): bool
    {
        return false;
    }
}
