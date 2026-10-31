<?php

namespace App\Modules\Core\Policies;

use App\Modules\Core\Models\SitePage;
use App\Modules\Core\Models\User;

/**
 * The public site's pages are the operator's, edited on the platform panel: super admins only,
 * the same rule as CompanyPolicy. The resource gates itself the same way; the policy makes it hold
 * everywhere the model is authorised.
 */
class SitePagePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function view(User $user, SitePage $page): bool
    {
        return $user->isSuperAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function update(User $user, SitePage $page): bool
    {
        return $user->isSuperAdmin();
    }

    public function delete(User $user, SitePage $page): bool
    {
        return $user->isSuperAdmin();
    }
}
