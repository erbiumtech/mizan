<?php

namespace App\Modules\ConstructionCosting\Policies;

use App\Modules\ConstructionCosting\Models\LabourRecord;
use App\Modules\Core\Models\User;

/**
 * Who records a day's work, and who turns it into cost — `docs/construction-management-plan.md` §7.1.
 *
 * **Recording is site's**, like a goods receipt and for the same reason: the ganger is the only person who knows who
 * turned up and what they did, and a sheet typed by the office from a note that reached it a week later is how a day
 * lands on the wrong job. Approving is somebody else's, because approving is what books the money.
 *
 * **Reversing rides on `ConstructionCostReverse`** rather than a name of its own. It is the grant that already governs
 * backing a posted entry out of the ledger, and reversing a labour record is exactly that — twice, since §7.3's burden
 * is its own entry.
 *
 * A draft may be deleted; approved labour may not. Once cost is booked the row is what explains a reversal pair on the
 * cost report, and §3.3's rule is that a correction is a further row rather than a disappearance.
 */
class LabourRecordPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ConstructionLabourView');
    }

    public function view(User $user, LabourRecord $record): bool
    {
        return $user->can('ConstructionLabourView');
    }

    public function create(User $user): bool
    {
        return $user->can('ConstructionLabourRecord');
    }

    /** Editable while it is a draft: §3.3's line, and a typo caught in ten seconds is not worth a reversal pair. */
    public function update(User $user, LabourRecord $record): bool
    {
        return $user->can('ConstructionLabourRecord') && $record->isDraft();
    }

    public function delete(User $user, LabourRecord $record): bool
    {
        return $user->can('ConstructionLabourRecord') && $record->isDraft();
    }

    /** The act that books the cost, and that freezes the rate it was costed at. */
    public function approve(User $user, LabourRecord $record): bool
    {
        return $user->can('ConstructionLabourApprove') && $record->isDraft();
    }

    public function reverse(User $user, LabourRecord $record): bool
    {
        return $user->can('ConstructionCostReverse') && $record->isApproved();
    }
}
