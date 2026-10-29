<?php

namespace App\Modules\Recruitment\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Recruitment\Models\Interview;

/** An interview travels with its application; the panel reads its own. */
class InterviewPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('ApplicantView');
    }

    public function view(User $user, Interview $interview): bool
    {
        return $user->hasPermissionTo('ApplicantView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('ApplicantUpdate');
    }

    public function update(User $user, Interview $interview): bool
    {
        return $user->hasPermissionTo('ApplicantUpdate');
    }

    public function delete(User $user, Interview $interview): bool
    {
        return $user->hasPermissionTo('ApplicantUpdate') && ! $interview->isDecided();
    }
}
