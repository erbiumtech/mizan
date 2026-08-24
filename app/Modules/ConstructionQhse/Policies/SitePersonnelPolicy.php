<?php

namespace App\Modules\ConstructionQhse\Policies;

use App\Modules\ConstructionQhse\Models\SitePersonnel;
use App\Modules\Core\Models\User;

/**
 * Who keeps the induction register — §17.5.
 *
 * **Two permissions, and the register-keeping one is wide.** Putting somebody on the register, inducting them and
 * recording their tickets is gate work: it happens at seven in the morning, by whoever is at the gate, for people who
 * arrived that day. A permission that made it a supervisor's job would produce a register that lags the site by a week —
 * and a register that lags is a register nobody trusts to say who is cleared to work.
 *
 * `ConstructionPersonnelView` is separate because the register holds names, phone numbers and medical certificates,
 * which is the most personal data in this module.
 */
class SitePersonnelPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ConstructionPersonnelView');
    }

    public function view(User $user, SitePersonnel $person): bool
    {
        return $user->can('ConstructionPersonnelView');
    }

    public function create(User $user): bool
    {
        return $user->can('ConstructionPersonnelUpdate');
    }

    public function update(User $user, SitePersonnel $person): bool
    {
        return $user->can('ConstructionPersonnelUpdate');
    }

    /** Inducting somebody is the register's own act, and the widest thing in it. */
    public function induct(User $user, SitePersonnel $person): bool
    {
        return $user->can('ConstructionPersonnelUpdate');
    }

    /**
     * Removing a row is allowed only while nothing has been recorded against it.
     *
     * Somebody who was inducted, or who attended a talk, is part of those records — and a register that could delete
     * them would let a site's history be tidied.
     */
    public function delete(User $user, SitePersonnel $person): bool
    {
        return $user->can('ConstructionPersonnelUpdate')
            && $person->inducted_on === null
            && $person->competencies()->count() === 0
            && $person->attendances()->count() === 0;
    }
}
