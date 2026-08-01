<?php

namespace App\Modules\Core\Policies;

use App\Modules\Core\Models\CustomField;
use App\Modules\Core\Models\User;

/**
 * Custom fields define the shape of other records, so managing them is an
 * administrative act rather than day-to-day data entry. There is no
 * CustomField* permission in the seeder, so this is role-based; super admins
 * pass through the global Gate::before in AppServiceProvider.
 */
class CustomFieldPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('Administrator');
    }

    public function view(User $user, CustomField $customField): bool
    {
        return $user->hasRole('Administrator');
    }

    public function create(User $user): bool
    {
        return $user->hasRole('Administrator');
    }

    public function update(User $user, CustomField $customField): bool
    {
        return $user->hasRole('Administrator');
    }

    public function delete(User $user, CustomField $customField): bool
    {
        return $user->hasRole('Administrator');
    }
}
