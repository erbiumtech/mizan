<?php

namespace App\Modules\Crm\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Crm\Models\FieldVisitRefusal;

/**
 * Read-only for everybody — docs/saas-launch-plan.md §14.10.
 *
 * Written by the application as a check-in is turned away, and never edited after: a
 * log of refusals that somebody can tidy is not a log.
 */
class FieldVisitRefusalPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('FieldVisitView');
    }

    public function view(User $user, FieldVisitRefusal $refusal): bool
    {
        return $user->can('FieldVisitView');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, FieldVisitRefusal $refusal): bool
    {
        return false;
    }

    public function delete(User $user, FieldVisitRefusal $refusal): bool
    {
        return false;
    }
}
