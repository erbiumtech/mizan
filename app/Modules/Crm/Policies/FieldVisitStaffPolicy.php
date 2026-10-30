<?php

namespace App\Modules\Crm\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Crm\Models\FieldVisitStaff;

/**
 * Who may be sent on visits is a decision about staffing, not about one visit —
 * docs/saas-launch-plan.md §14.10. `FieldVisitDelete` is the narrowest right the
 * module already has that is held by the people who make it.
 */
class FieldVisitStaffPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('FieldVisitView');
    }

    public function view(User $user, FieldVisitStaff $staff): bool
    {
        return $user->can('FieldVisitView');
    }

    public function create(User $user): bool
    {
        return $user->can('FieldVisitDelete');
    }

    public function update(User $user, FieldVisitStaff $staff): bool
    {
        return $user->can('FieldVisitDelete');
    }

    public function delete(User $user, FieldVisitStaff $staff): bool
    {
        return $user->can('FieldVisitDelete');
    }
}
