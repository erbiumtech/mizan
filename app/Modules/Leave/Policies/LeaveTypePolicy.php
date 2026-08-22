<?php

namespace App\Modules\Leave\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Leave\Models\LeaveType;

class LeaveTypePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('LeaveTypeView');
    }

    public function view(User $user, LeaveType $type): bool
    {
        return $user->hasPermissionTo('LeaveTypeView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('LeaveTypeCreate');
    }

    public function update(User $user, LeaveType $type): bool
    {
        return $user->hasPermissionTo('LeaveTypeUpdate');
    }

    /**
     * A type nobody has used may go; one with entitlements or requests against it
     * may not.
     *
     * The database says the same thing — both foreign keys restrict on delete — but
     * a policy that lets somebody press a button the database will refuse produces
     * a 500 rather than an explanation. Deactivating is the answer, which is what
     * is_active is for.
     */
    public function delete(User $user, LeaveType $type): bool
    {
        return $user->hasPermissionTo('LeaveTypeDelete')
            && ! $type->entitlements()->exists()
            && ! $type->requests()->exists();
    }
}
