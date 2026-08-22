<?php

namespace App\Modules\ConstructionQhse\Policies;

use App\Modules\ConstructionQhse\Models\Inspection;
use App\Modules\Core\Models\User;

/**
 * Who requests an inspection, and who releases a hold point — §17.1.
 *
 * **`ConstructionInspectionRelease` is the one permission in this module that has to be its own**, and §17.1's sentence
 * is the argument: "the whole function of a hold point is that work may not proceed past it". Releasing one authorises
 * the next operation to start — a pour, a backfill, a cladding line. Whoever *records* that an inspection happened is
 * not necessarily whoever may say the work may proceed, and on a site where those are the same person the hold point has
 * no function at all.
 *
 * **Requesting and recording are one grant**, deliberately: the request goes out and the result comes back to the same
 * engineer, and splitting them would leave results in a notebook while the register says the inspection is still
 * awaited.
 */
class InspectionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ConstructionInspectionView');
    }

    public function view(User $user, Inspection $inspection): bool
    {
        return $user->can('ConstructionInspectionView');
    }

    public function create(User $user): bool
    {
        return $user->can('ConstructionInspectionUpdate');
    }

    /** Editable while it is open: a recorded inspection is the evidence of what was found. */
    public function update(User $user, Inspection $inspection): bool
    {
        return $user->can('ConstructionInspectionUpdate') && $inspection->isOpen();
    }

    public function record(User $user, Inspection $inspection): bool
    {
        return $user->can('ConstructionInspectionUpdate') && $inspection->isOpen();
    }

    /** The act that lets work proceed. See the class docblock. */
    public function release(User $user, Inspection $inspection): bool
    {
        return $user->can('ConstructionInspectionRelease') && $inspection->awaitingRelease();
    }

    /**
     * Deletable only while nothing has been recorded against it.
     *
     * An inspection somebody carried out is a record of what was found, and a hold point's release history is what a
     * certification audit reads.
     */
    public function delete(User $user, Inspection $inspection): bool
    {
        return $user->can('ConstructionInspectionUpdate')
            && $inspection->status === Inspection::STATUS_REQUESTED
            && $inspection->checks()->count() === 0;
    }
}
