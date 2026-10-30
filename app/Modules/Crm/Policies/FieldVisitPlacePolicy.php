<?php

namespace App\Modules\Crm\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Crm\Models\FieldVisitPlace;

/**
 * Pinning a client's place is part of recording a visit — docs/saas-launch-plan.md §14.10.
 *
 * So it takes the visit permissions rather than its own: the person who should drop
 * the pin is the rep standing at the door, and giving them a second permission to do
 * the thing they are already there to do is a permission nobody grants.
 */
class FieldVisitPlacePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('FieldVisitView');
    }

    public function view(User $user, FieldVisitPlace $place): bool
    {
        return $user->can('FieldVisitView');
    }

    public function create(User $user): bool
    {
        return $user->can('FieldVisitUpdate');
    }

    public function update(User $user, FieldVisitPlace $place): bool
    {
        return $user->can('FieldVisitUpdate');
    }

    public function delete(User $user, FieldVisitPlace $place): bool
    {
        return $user->can('FieldVisitUpdate');
    }
}
