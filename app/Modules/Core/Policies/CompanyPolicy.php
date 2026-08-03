<?php

namespace App\Modules\Core\Policies;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;

/**
 * Company (tenant) management is a super-admin feature: it provisions and drops
 * whole databases. CompanyResource already gates itself the same way, but the
 * policy makes the rule apply everywhere the model is authorized — actions,
 * relation managers, and the command palette included.
 */
class CompanyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function view(User $user, Company $company): bool
    {
        return $user->isSuperAdmin();
    }

    /**
     * Same rule, kept behind canCreateCompanies() because the tenant registration
     * page asks that question directly — see RegisterCompany::canView().
     */
    public function create(User $user): bool
    {
        return $user->canCreateCompanies();
    }

    public function update(User $user, Company $company): bool
    {
        return $user->isSuperAdmin();
    }

    public function delete(User $user, Company $company): bool
    {
        return $user->isSuperAdmin();
    }
}
