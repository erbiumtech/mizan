<?php

namespace App\Modules\ConstructionCosting\Policies;

use App\Modules\ConstructionCosting\Models\CostEntry;
use App\Modules\Core\Models\User;

/**
 * Who may see, record and reverse job cost.
 *
 * **There is no `delete`, and that is the design** — §3.2's invariant is that the sum of `amount` filtered by
 * nothing but the period *is* the cost. A deletable entry means a cost report whose history depends on what
 * somebody removed, and the correction mechanism is a reversal that leaves both rows on the ledger.
 *
 * `update` answers "may this role edit at all"; whether *this* entry has hardened is `CostEntry::isEditable()`
 * and `CostLedger::amend()`. Keeping them apart matters: a permission grant must not be able to skip the
 * closed-period rule.
 */
class CostEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ConstructionCostView');
    }

    public function view(User $user, CostEntry $entry): bool
    {
        return $user->can('ConstructionCostView');
    }

    public function create(User $user): bool
    {
        return $user->can('ConstructionCostCreate');
    }

    public function update(User $user, CostEntry $entry): bool
    {
        return $user->can('ConstructionCostUpdate') && $entry->isEditable();
    }

    /** Reversing is an approval-shaped act, kept away from whoever recorded the cost. */
    public function reverse(User $user, CostEntry $entry): bool
    {
        return $user->can('ConstructionCostReverse') && ! $entry->isReversed() && ! $entry->isReversal();
    }
}
