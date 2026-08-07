<?php

namespace App\Modules\Accounting\Policies;

use App\Modules\Accounting\Models\ScheduledTransaction;
use App\Modules\Core\Models\User;

/**
 * A schedule writes journal entries without anybody present, so creating one is
 * held to the same permission as creating an entry by hand — and running one
 * early is held to the higher bar, because that is what actually puts rows in
 * the ledger.
 */
class ScheduledTransactionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('JournalEntryView');
    }

    public function view(User $user, ScheduledTransaction $schedule): bool
    {
        return $user->hasPermissionTo('JournalEntryView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('JournalEntryCreate');
    }

    public function update(User $user, ScheduledTransaction $schedule): bool
    {
        return $user->hasPermissionTo('JournalEntryUpdate');
    }

    public function delete(User $user, ScheduledTransaction $schedule): bool
    {
        return $user->hasPermissionTo('JournalEntryDelete');
    }

    /**
     * Raise the outstanding entries now rather than waiting for tonight.
     *
     * Deliberately JournalEntryCreate and not Update: this is the button that
     * actually writes to the ledger, so it belongs with the permission for
     * writing to the ledger. The entries are still drafts and still need an
     * approver.
     */
    public function runNow(User $user, ScheduledTransaction $schedule): bool
    {
        return $user->hasPermissionTo('JournalEntryCreate') && $schedule->is_active;
    }
}
