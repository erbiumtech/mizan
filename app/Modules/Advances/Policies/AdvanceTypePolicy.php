<?php

namespace App\Modules\Advances\Policies;

use App\Modules\Advances\Models\AdvanceType;
use App\Modules\Core\Models\User;

class AdvanceTypePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('AdvanceView');
    }

    public function view(User $user, AdvanceType $type): bool
    {
        return $user->hasPermissionTo('AdvanceView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('AdvanceUpdate');
    }

    public function update(User $user, AdvanceType $type): bool
    {
        return $user->hasPermissionTo('AdvanceUpdate');
    }

    public function delete(User $user, AdvanceType $type): bool
    {
        return $user->hasPermissionTo('AdvanceUpdate') && ! $type->advances()->exists();
    }
}
