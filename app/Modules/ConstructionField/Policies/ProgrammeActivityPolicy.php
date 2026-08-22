<?php

namespace App\Modules\ConstructionField\Policies;

use App\Modules\ConstructionField\Models\ProgrammeActivity;
use App\Modules\Core\Models\User;

/**
 * Who maintains the programme — §13.
 *
 * **Three permissions, and this is the first register in §16 where a third one earns its place.** Reading and editing
 * the programme is planning work. **Recording progress is a separate grant**, because percent complete and actual dates
 * are what §14's earned value and every schedule index are computed from — and the person who reports 80% is not
 * usually the person who owns the consequence of it being 60%. Progress claimed against a programme is the oldest
 * optimism in construction, and it is the one number on this table that feeds money.
 *
 * **There is no separate grant for the baseline**, and that is deliberate rather than an omission: the baseline is the
 * *accepted* programme, and this application does not accept programmes — it stores what P6 exported. Guarding a column
 * that only an import writes would be theatre.
 */
class ProgrammeActivityPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ConstructionProgrammeView');
    }

    public function view(User $user, ProgrammeActivity $activity): bool
    {
        return $user->can('ConstructionProgrammeView');
    }

    public function create(User $user): bool
    {
        return $user->can('ConstructionProgrammeUpdate');
    }

    public function update(User $user, ProgrammeActivity $activity): bool
    {
        return $user->can('ConstructionProgrammeUpdate');
    }

    /** The one number on this table that feeds money. See the class docblock. */
    public function progress(User $user, ProgrammeActivity $activity): bool
    {
        return $user->can('ConstructionProgrammeProgress');
    }

    /**
     * An imported activity is not deletable.
     *
     * Deleting one would put this application's copy of the programme out of step with the file it was imported from,
     * and the next import would silently put it back — which is worse than refusing, because nobody would notice
     * either event.
     */
    public function delete(User $user, ProgrammeActivity $activity): bool
    {
        return $user->can('ConstructionProgrammeUpdate') && ! $activity->isImported();
    }
}
