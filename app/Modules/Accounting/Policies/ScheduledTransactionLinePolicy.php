<?php

namespace App\Modules\Accounting\Policies;

use App\Modules\Accounting\Models\ScheduledTransactionLine;
use App\Modules\Core\Models\User;

/** Defers to the schedule it belongs to; see ScheduledTransactionPolicy. */
class ScheduledTransactionLinePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('JournalEntryView');
    }

    public function view(User $user, ScheduledTransactionLine $line): bool
    {
        return $user->hasPermissionTo('JournalEntryView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('JournalEntryCreate');
    }

    public function update(User $user, ScheduledTransactionLine $line): bool
    {
        return $user->hasPermissionTo('JournalEntryUpdate');
    }

    public function delete(User $user, ScheduledTransactionLine $line): bool
    {
        return $user->hasPermissionTo('JournalEntryUpdate');
    }
}
