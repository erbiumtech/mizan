<?php

namespace App\Modules\Performance\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Performance\Models\ScorecardProfile;

/**
 * What a job's score is weighted by is a management decision — docs/saas-launch-plan.md §14.10.
 *
 * It takes the review permissions rather than its own: whoever decides how somebody
 * is appraised is whoever decides what their week is worth.
 */
class ScorecardProfilePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ReviewView');
    }

    public function view(User $user, ScorecardProfile $profile): bool
    {
        return $user->can('ReviewView');
    }

    public function create(User $user): bool
    {
        return $user->can('ReviewUpdate');
    }

    public function update(User $user, ScorecardProfile $profile): bool
    {
        return $user->can('ReviewUpdate');
    }

    public function delete(User $user, ScorecardProfile $profile): bool
    {
        return $user->can('ReviewUpdate');
    }
}
