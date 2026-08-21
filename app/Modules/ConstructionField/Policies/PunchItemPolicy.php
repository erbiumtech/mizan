<?php

namespace App\Modules\ConstructionField\Policies;

use App\Modules\ConstructionField\Models\PunchItem;
use App\Modules\Core\Models\User;

/**
 * One snag — §16.4. See `PunchListPolicy` for why there are two permissions rather than three.
 *
 * The `inspect` ability is the interesting one: it is the only route to a closed item, so it carries the money. It is
 * the same grant as raising, because on a real site the person who checks the sealant was redone is the person who
 * wrote it down — and the protection is that the *result* has to be a pass, recorded as its own row, with the attempt
 * counted.
 */
class PunchItemPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ConstructionPunchView');
    }

    public function view(User $user, PunchItem $item): bool
    {
        return $user->can('ConstructionPunchView');
    }

    public function create(User $user): bool
    {
        return $user->can('ConstructionPunchUpdate');
    }

    public function update(User $user, PunchItem $item): bool
    {
        return $user->can('ConstructionPunchUpdate') && ! $item->isClosed();
    }

    /** The only way an item closes, and the reason it cannot be closed by assertion. */
    public function inspect(User $user, PunchItem $item): bool
    {
        return $user->can('ConstructionPunchUpdate') && ! $item->isClosed();
    }

    public function reject(User $user, PunchItem $item): bool
    {
        return $user->can('ConstructionPunchUpdate') && ! $item->isClosed();
    }

    /** No delete: an item somebody walked round site and looked at is a record of what was found. */
    public function delete(User $user, PunchItem $item): bool
    {
        return $user->can('ConstructionPunchUpdate')
            && $item->status === PunchItem::STATUS_OPEN
            && $item->inspections()->count() === 0;
    }
}
