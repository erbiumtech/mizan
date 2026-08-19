<?php

namespace App\Modules\ConstructionCosting\Policies;

use App\Modules\ConstructionCosting\Models\MaterialIssue;
use App\Modules\Core\Models\User;

/**
 * Who issues material out of a site store — `docs/construction-management-plan.md` §6.
 *
 * **One new permission, and reading rides on the receipt's.** Receiving a delivery into a store and issuing it back out
 * are the same person's job on the same screenful — the storeman — so `ConstructionReceiptView` is the grant that opens
 * both registers. A second view name would be another row in every role form for a decision nobody makes separately.
 *
 * **`ConstructionMaterialIssue` covers writing the docket and posting it**, following the goods receipt exactly:
 * `ConstructionReceiptRecord` is record-and-post in one, because the storeman signs the paper and the movement is the
 * same act. Splitting them would leave a queue of dockets whose material has physically gone.
 *
 * **Reversing rides on `ConstructionCostReverse`**, the grant that already governs backing a posted entry out of the
 * ledger — and a reversal here does exactly that, twice per line, as well as putting stock back.
 */
class MaterialIssuePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ConstructionReceiptView');
    }

    public function view(User $user, MaterialIssue $issue): bool
    {
        return $user->can('ConstructionReceiptView');
    }

    public function create(User $user): bool
    {
        return $user->can('ConstructionMaterialIssue');
    }

    /** Editable while it is a draft: once posted the material has gone and the stock has moved. */
    public function update(User $user, MaterialIssue $issue): bool
    {
        return $user->can('ConstructionMaterialIssue') && $issue->isDraft();
    }

    public function delete(User $user, MaterialIssue $issue): bool
    {
        return $user->can('ConstructionMaterialIssue') && $issue->isDraft();
    }

    /** The act that takes the stock and moves the cost between codes. */
    public function post(User $user, MaterialIssue $issue): bool
    {
        return $user->can('ConstructionMaterialIssue') && $issue->isDraft();
    }

    public function reverse(User $user, MaterialIssue $issue): bool
    {
        return $user->can('ConstructionCostReverse') && $issue->isPosted();
    }
}
