<?php

namespace App\Modules\ConstructionQhse\Policies;

use App\Modules\ConstructionQhse\Models\Incident;
use App\Modules\Core\Models\User;

/**
 * Who reports an incident, who investigates it, and who tells the authority — §17.3.
 *
 * **Reporting is the widest grant in this whole module, deliberately.** §17.3's leading indicator is near misses per
 * lost-time injury, and a permission that made reporting hard would suppress exactly the number it most needs. Anybody
 * who can see something nearly go wrong should be able to write it down.
 *
 * **`ConstructionIncidentInvestigate` is separate**, because closing an incident asserts that its cause is understood
 * and its lesson recorded — and the person who was involved is not the person to conclude that. It also carries the
 * authority report, which is a statutory duty with somebody's name on it.
 */
class IncidentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ConstructionIncidentView');
    }

    public function view(User $user, Incident $incident): bool
    {
        return $user->can('ConstructionIncidentView');
    }

    /** The widest grant in the module. See the class docblock. */
    public function create(User $user): bool
    {
        return $user->can('ConstructionIncidentReport');
    }

    public function update(User $user, Incident $incident): bool
    {
        return $user->can('ConstructionIncidentReport') && ! $incident->isClosed();
    }

    public function investigate(User $user, Incident $incident): bool
    {
        return $user->can('ConstructionIncidentInvestigate') && ! $incident->isClosed();
    }

    /** A statutory duty, so it sits with whoever investigates rather than with whoever reported. */
    public function reportToAuthority(User $user, Incident $incident): bool
    {
        return $user->can('ConstructionIncidentInvestigate')
            && $incident->reportable_to_authority
            && $incident->reported_to_authority_on === null;
    }

    public function close(User $user, Incident $incident): bool
    {
        return $user->can('ConstructionIncidentInvestigate') && ! $incident->isClosed();
    }

    public function reopen(User $user, Incident $incident): bool
    {
        return $user->can('ConstructionIncidentInvestigate') && $incident->isClosed();
    }

    /** No delete. An incident record is the only account of something that happened to somebody. */
    public function delete(User $user, Incident $incident): bool
    {
        return false;
    }
}
