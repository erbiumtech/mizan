<?php

namespace App\Modules\Employees\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\LetterRequest;

/**
 * Anybody may ask; somebody with `LetterIssue` decides — docs/saas-launch-plan.md §14.7.
 *
 * Asking is deliberately not a permission, for the reason `EmployeeChangeRequest` is
 * not: an employee needing a letter for a visa application is the ordinary case, and
 * a company that had to grant each person the right to ask would end up with HR
 * raising the requests itself, which is the queue this replaces.
 *
 * Seeing one is the same rule as seeing an employee: your own always, everybody's
 * with `LetterView`. A refusal reason is written for the person it is about.
 */
class LetterRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, LetterRequest $request): bool
    {
        return $user->hasPermissionTo('LetterView') || $request->employee?->user_id === $user->getKey();
    }

    public function create(User $user): bool
    {
        return true;
    }

    /** Issuing and refusing both go through `update`: each one decides the request. */
    public function update(User $user, LetterRequest $request): bool
    {
        return $user->hasPermissionTo('LetterIssue');
    }

    /**
     * Withdrawn by the person who asked, while it is still waiting.
     *
     * Once HR has answered it is a record of what was asked and what was decided, and
     * somebody who has just been refused should not be able to tidy that away.
     */
    public function delete(User $user, LetterRequest $request): bool
    {
        return $request->isPending()
            && ($user->hasPermissionTo('LetterIssue') || $request->employee?->user_id === $user->getKey());
    }
}
