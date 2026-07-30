<?php

namespace App\Modules\Accounting\Policies;

use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Core\Models\User;

/**
 * Journal entry lines are the general ledger. They follow the journal entry
 * permissions; previously the resource had no policy, so Filament allowed
 * everyone — including plain employees — to read the ledger.
 */
class JournalEntryLinePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('JournalEntryView');
    }

    public function view(User $user, JournalEntryLine $journalEntryLine): bool
    {
        return $user->hasPermissionTo('JournalEntryView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('JournalEntryCreate');
    }

    public function update(User $user, JournalEntryLine $journalEntryLine): bool
    {
        return $user->hasPermissionTo('JournalEntryUpdate');
    }

    public function delete(User $user, JournalEntryLine $journalEntryLine): bool
    {
        return $user->hasPermissionTo('JournalEntryDelete');
    }
}
