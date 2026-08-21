<?php

namespace App\Modules\ConstructionField\Policies;

use App\Modules\ConstructionField\Models\Submittal;
use App\Modules\Core\Models\User;

/**
 * Who maintains the submittal register — §16.3.
 *
 * **Two permissions, and recording a reviewer's return is the same grant as submitting.** The return arrives stamped
 * from the Architect and somebody transcribes it — clerical work, not an approval, and the same reasoning §16.2's RFI
 * answer gets. A permission there would leave stamped drawings sitting in a drawer while the register says the item is
 * still out for review, which is worse than the risk it guards: the register's only job is to be believable about what
 * is outstanding.
 *
 * **The one act that is separated already is separated.** Notifying a reviewer's overrun asks for
 * `ConstructionDelayUpdate`, because serving notice on the employer is not the same act as filing paperwork.
 *
 * **There is no delete once anything has been submitted.** A submittal with rounds against it is the schedule record of
 * how many times an item went round, and §16.3 exists because that fact is otherwise lost.
 */
class SubmittalPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ConstructionSubmittalView');
    }

    public function view(User $user, Submittal $submittal): bool
    {
        return $user->can('ConstructionSubmittalView');
    }

    public function create(User $user): bool
    {
        return $user->can('ConstructionSubmittalUpdate');
    }

    /** Editable until it is cleared: after that the lead times are the record of what the approval bought. */
    public function update(User $user, Submittal $submittal): bool
    {
        return $user->can('ConstructionSubmittalUpdate') && ! $submittal->isCleared();
    }

    public function submit(User $user, Submittal $submittal): bool
    {
        return $user->can('ConstructionSubmittalUpdate') && ! $submittal->isCleared();
    }

    public function recordReturn(User $user, Submittal $submittal): bool
    {
        return $user->can('ConstructionSubmittalUpdate');
    }

    /** The delay register's grant, not this one — see the class docblock. */
    public function raiseDelay(User $user, Submittal $submittal): bool
    {
        return $user->can('ConstructionDelayUpdate');
    }

    /**
     * Deletable only while it has never been submitted.
     *
     * A row somebody added to the wrong section before anything happened is a mistake worth removing. One with rounds
     * against it is history — §16.3's whole point is that the number of rounds survives.
     */
    public function delete(User $user, Submittal $submittal): bool
    {
        return $user->can('ConstructionSubmittalUpdate')
            && $submittal->submitted_on === null
            && $submittal->reviews()->count() === 0;
    }
}
