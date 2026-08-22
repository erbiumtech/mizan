<?php

namespace App\Modules\Crm\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Crm\Models\LeadSource;

class LeadSourcePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('LeadSourceView');
    }

    public function view(User $user, LeadSource $source): bool
    {
        return $user->hasPermissionTo('LeadSourceView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('LeadSourceCreate');
    }

    public function update(User $user, LeadSource $source): bool
    {
        return $user->hasPermissionTo('LeadSourceUpdate');
    }

    /**
     * A source with leads against it stays.
     *
     * The foreign key nulls on delete rather than restricting, which means deleting a
     * source would silently detach every lead that came from it — and win/loss by
     * source, the report the table exists for, would lose that history without saying
     * so. Deactivate instead.
     */
    public function delete(User $user, LeadSource $source): bool
    {
        return $user->hasPermissionTo('LeadSourceDelete') && ! $source->leads()->exists();
    }
}
