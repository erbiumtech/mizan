<?php

namespace App\Modules\Core\Policies;

use App\Modules\Core\Models\ContactMessage;
use App\Modules\Core\Models\User;

/**
 * Messages from the public contact form are read, marked handled and, once dealt with, deleted on the
 * platform panel by super admins only. Nobody creates one from the panel — the form does.
 */
class ContactMessagePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function view(User $user, ContactMessage $message): bool
    {
        return $user->isSuperAdmin();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, ContactMessage $message): bool
    {
        return $user->isSuperAdmin();
    }

    public function delete(User $user, ContactMessage $message): bool
    {
        return $user->isSuperAdmin();
    }
}
