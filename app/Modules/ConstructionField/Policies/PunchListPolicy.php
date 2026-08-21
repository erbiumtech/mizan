<?php

namespace App\Modules\ConstructionField\Policies;

use App\Modules\ConstructionField\Models\PunchList;
use App\Modules\Core\Models\User;

/**
 * Who keeps the snag list — §16.4.
 *
 * **Two permissions, and the segregation that matters here is structural rather than granted.** Closing a punch item
 * releases part of §11's AIA holdback, so it is the one act on this register that moves money — and it is protected by
 * requiring an inspection that *passed*, not by a third permission. That is stronger: a permission can be granted to
 * the person who caused the defect, and a missing passed re-inspection cannot be granted away at all.
 *
 * Raising items is site's for the same reason as the diary and the RFI: the person who can see that the sealant is wrong
 * is the person standing in front of it.
 *
 * **There is no delete once anything has been inspected**, and rejection is a status with a reason rather than a
 * removal — "we agreed this was not a defect on the 14th" is the answer to a question somebody asks again in month nine.
 */
class PunchListPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ConstructionPunchView');
    }

    public function view(User $user, PunchList $list): bool
    {
        return $user->can('ConstructionPunchView');
    }

    public function create(User $user): bool
    {
        return $user->can('ConstructionPunchUpdate');
    }

    public function update(User $user, PunchList $list): bool
    {
        return $user->can('ConstructionPunchUpdate') && ! $list->isClosed();
    }

    /** Closing the list is refused by the service while items are open, whoever holds this. */
    public function close(User $user, PunchList $list): bool
    {
        return $user->can('ConstructionPunchUpdate') && ! $list->isClosed();
    }

    /**
     * Deletable only while empty.
     *
     * A list somebody opened against the wrong job before walking anywhere is a mistake worth removing. One with items
     * on it is the record of a walk-round.
     */
    public function delete(User $user, PunchList $list): bool
    {
        return $user->can('ConstructionPunchUpdate') && $list->items()->count() === 0;
    }
}
