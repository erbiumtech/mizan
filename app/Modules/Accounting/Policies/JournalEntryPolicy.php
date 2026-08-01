<?php

namespace App\Modules\Accounting\Policies;

use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Core\Models\User;

class JournalEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('JournalEntryView');
    }

    public function view(User $user, JournalEntry $entry): bool
    {
        return $user->hasPermissionTo('JournalEntryView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('JournalEntryCreate');
    }

    public function update(User $user, JournalEntry $entry): bool
    {
        // Posted/pending/approved entries are immutable; corrections go through reversal.
        return $user->hasPermissionTo('JournalEntryUpdate') && $entry->isEditable();
    }

    public function delete(User $user, JournalEntry $entry): bool
    {
        return $user->hasPermissionTo('JournalEntryDelete') && $entry->isEditable();
    }

    public function submit(User $user, JournalEntry $entry): bool
    {
        return $user->hasPermissionTo('JournalEntrySubmit') && $entry->isEditable();
    }

    public function approve(User $user, JournalEntry $entry): bool
    {
        if (! $user->hasPermissionTo('JournalEntryApprove')) {
            return false;
        }

        // Segregation of duties: the creator may never approve their own entry.
        return $entry->created_by === null || $entry->created_by !== $user->id;
    }

    public function reject(User $user, JournalEntry $entry): bool
    {
        return $user->hasPermissionTo('JournalEntryReject');
    }

    public function post(User $user, JournalEntry $entry): bool
    {
        return $user->hasPermissionTo('JournalEntryPost') && $entry->canBePosted();
    }

    public function reverse(User $user, JournalEntry $entry): bool
    {
        return $user->hasPermissionTo('JournalEntryReverse') && $entry->is_posted;
    }
}
