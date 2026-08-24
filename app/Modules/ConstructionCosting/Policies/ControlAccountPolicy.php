<?php

namespace App\Modules\ConstructionCosting\Policies;

use App\Modules\ConstructionCosting\Models\ControlAccount;
use App\Modules\Core\Models\User;

/**
 * Who nominates which general-ledger accounts §4 is about.
 *
 * **On `ConstructionGlPost` rather than earning a name of its own**, and the argument is §18.2's: nominating control
 * accounts and posting to them is one screenful of decisions taken once at implementation by whoever owns the chart of
 * accounts. A separate permission would be another row in every role form for a decision nobody makes separately.
 *
 * Reading is on `ConstructionCostView`, because the reconciliation report names these accounts back and a book-keeper
 * reading a difference needs to see which accounts it was computed from.
 *
 * **Deleting is allowed and matters.** A company that nominated the wrong account has to be able to take it out — the
 * alternative is a reconciliation reading an account nobody meant, forever. Nothing depends on the row historically:
 * §4.1's trail is `construction_cost_entries.journal_entry_id`, which survives the nomination being withdrawn.
 */
class ControlAccountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ConstructionCostView');
    }

    public function view(User $user, ControlAccount $account): bool
    {
        return $user->can('ConstructionCostView');
    }

    public function create(User $user): bool
    {
        return $user->can('ConstructionGlPost');
    }

    public function update(User $user, ControlAccount $account): bool
    {
        return $user->can('ConstructionGlPost');
    }

    public function delete(User $user, ControlAccount $account): bool
    {
        return $user->can('ConstructionGlPost');
    }
}
