<?php

namespace App\Modules\Recruitment\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Recruitment\Models\InterviewFeedback;

/** Written by the interviewer it belongs to; read by whoever runs the pipeline. */
class InterviewFeedbackPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('ApplicantView');
    }

    public function view(User $user, InterviewFeedback $feedback): bool
    {
        return $user->hasPermissionTo('ApplicantView') || (int) $feedback->user_id === (int) $user->getKey();
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, InterviewFeedback $feedback): bool
    {
        return (int) $feedback->user_id === (int) $user->getKey();
    }

    public function delete(User $user, InterviewFeedback $feedback): bool
    {
        return false;
    }
}
