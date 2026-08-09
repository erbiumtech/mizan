<?php

namespace App\Modules\Core\Policies;

use App\Modules\Core\Models\EmailTemplate;
use App\Modules\Core\Models\User;

/**
 * What the company says to its own staff and clients is an administrative setting, so
 * it takes the company-settings permissions.
 */
class EmailTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdministrator();
    }

    public function view(User $user, EmailTemplate $template): bool
    {
        return $user->isAdministrator();
    }

    public function create(User $user): bool
    {
        return $user->isAdministrator();
    }

    public function update(User $user, EmailTemplate $template): bool
    {
        return $user->isAdministrator();
    }

    /** Deleting a template restores the shipped wording, which is a safe thing to do. */
    public function delete(User $user, EmailTemplate $template): bool
    {
        return $user->isAdministrator();
    }
}
