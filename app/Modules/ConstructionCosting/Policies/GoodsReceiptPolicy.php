<?php

namespace App\Modules\ConstructionCosting\Policies;

use App\Modules\ConstructionCosting\Models\GoodsReceipt;
use App\Modules\Core\Models\User;

/**
 * Who may record a delivery, and who may back one out — `docs/construction-management-plan.md` §5.
 *
 * **Site records deliveries.** The storeman or the site engineer signs the delivery note, and they are the only people
 * who know what actually arrived. A receipt typed by the office from a note that reached it a week later is how a
 * delivery comes to be recorded against the wrong job — so `ConstructionReceiptRecord` sits with the Employee role,
 * alongside raising a requisition.
 *
 * **Reversing is not site's.** A posted receipt has relieved an order and put accrued cost on a job; taking that back
 * changes two registers, and it belongs with whoever answers for the figures rather than with whoever typed the note.
 *
 * There is no `delete`. A posted receipt is a fact about a day, and the correction is a reversal that says so.
 */
class GoodsReceiptPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ConstructionReceiptView');
    }

    public function view(User $user, GoodsReceipt $receipt): bool
    {
        return $user->can('ConstructionReceiptView');
    }

    public function create(User $user): bool
    {
        return $user->can('ConstructionReceiptRecord');
    }

    /** Editable only while it is a draft: posting has moved the committed figure and the cost report. */
    public function update(User $user, GoodsReceipt $receipt): bool
    {
        return $user->can('ConstructionReceiptRecord') && $receipt->isDraft();
    }

    /** Posting is the same grant as recording: the person signing the note is the person who knows it arrived. */
    public function post(User $user, GoodsReceipt $receipt): bool
    {
        return $user->can('ConstructionReceiptRecord') && $receipt->isDraft();
    }

    public function reverse(User $user, GoodsReceipt $receipt): bool
    {
        return $user->can('ConstructionReceiptReverse') && $receipt->isPosted();
    }
}
