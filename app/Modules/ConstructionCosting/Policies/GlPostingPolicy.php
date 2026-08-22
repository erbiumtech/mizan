<?php

namespace App\Modules\ConstructionCosting\Policies;

use App\Modules\ConstructionCosting\Models\GlPosting;
use App\Modules\Core\Models\User;

/**
 * Who may write construction's summary journals into the books.
 *
 * **`ConstructionGlPost` is its own name, and it is the only act in this module that leaves it.** Every other
 * permission here governs a figure inside the job-cost ledger; this one creates journal entries in the general ledger,
 * which is another module's, appears in the trial balance and changes the company's reported cost. §18.2's test is
 * whether it is a separate decision made by a separate person, and it plainly is: a quantity surveyor approves cost and
 * a book-keeper decides what reaches the accounts.
 *
 * **Reversing rides on the same name rather than a `ConstructionGlUnpost`.** Whoever may put a figure in the books is
 * who may take it back out, and a separate permission would leave a wrong posting sitting there while somebody went
 * looking for the person who held it. The control on a reversal is the required reason, not a second grant.
 *
 * **No delete.** A posting is a row that says what reached the accounts and when. Deleting it would leave a journal
 * entry in the general ledger that nothing in this module claims — which is precisely the "number in the accounts
 * nobody can explain" that §4.1 calls a defect.
 */
class GlPostingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ConstructionCostView');
    }

    public function view(User $user, GlPosting $posting): bool
    {
        return $user->can('ConstructionCostView');
    }

    public function create(User $user): bool
    {
        return $user->can('ConstructionGlPost');
    }

    /** A posted run is a fact. What changes it is a reversal, which is `reverse()`. */
    public function update(User $user, GlPosting $posting): bool
    {
        return false;
    }

    public function reverse(User $user, GlPosting $posting): bool
    {
        return $user->can('ConstructionGlPost')
            && ! $posting->isReversal()
            && ! $posting->isReversed();
    }

    public function delete(User $user, GlPosting $posting): bool
    {
        return false;
    }
}
