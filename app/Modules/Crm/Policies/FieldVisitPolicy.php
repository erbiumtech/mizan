<?php

namespace App\Modules\Crm\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Crm\Models\FieldVisit;

class FieldVisitPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('FieldVisitView');
    }

    public function view(User $user, FieldVisit $visit): bool
    {
        return $user->hasPermissionTo('FieldVisitView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('FieldVisitCreate');
    }

    public function update(User $user, FieldVisit $visit): bool
    {
        return $user->hasPermissionTo('FieldVisitUpdate');
    }

    public function delete(User $user, FieldVisit $visit): bool
    {
        return $user->hasPermissionTo('FieldVisitDelete') && ! $visit->isCheckedOut();
    }
}
