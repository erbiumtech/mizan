<?php

namespace App\Modules\Employees\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\LetterTemplate;

/**
 * One permission for all of it — docs/saas-launch-plan.md §14.7.
 *
 * Whoever may change a template writes what every future increment letter says, on
 * company letterhead, to somebody who will keep it. Reading the wording and changing
 * it are not usefully different rights: a person trusted to see the draft is trusted
 * to edit it, and a person who is not should not be on this screen at all.
 */
class LetterTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('LetterTemplateUpdate');
    }

    public function view(User $user, LetterTemplate $template): bool
    {
        return $user->hasPermissionTo('LetterTemplateUpdate');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('LetterTemplateUpdate');
    }

    public function update(User $user, LetterTemplate $template): bool
    {
        return $user->hasPermissionTo('LetterTemplateUpdate');
    }

    /** Deleting one stops that letter being issued at all, which is a reversible refusal. */
    public function delete(User $user, LetterTemplate $template): bool
    {
        return $user->hasPermissionTo('LetterTemplateUpdate');
    }
}
