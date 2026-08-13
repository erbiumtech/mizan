<?php

namespace App\Modules\Recruitment\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Recruitment\Models\Applicant;

/**
 * Applicants, their applications, interviews and offers share ONE permission group.
 *
 * They are one pipeline, and nobody grants "may see an applicant but not their
 * interview". More importantly, splitting them would invite a role that can read CVs
 * without being trusted with the rest — and a CV is the most sensitive record here.
 *
 * The Employee role holds none of this.
 */
class ApplicantPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('ApplicantView');
    }

    public function view(User $user, Applicant $record): bool
    {
        return $user->hasPermissionTo('ApplicantView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('ApplicantCreate');
    }

    public function update(User $user, Applicant $record): bool
    {
        return $user->hasPermissionTo('ApplicantUpdate');
    }

    public function delete(User $user, Applicant $record): bool
    {
        return $user->hasPermissionTo('ApplicantDelete');
    }
}
