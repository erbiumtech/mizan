<?php

namespace App\Modules\Inventory\Policies;

use App\Modules\Inventory\Models\StockMovement;
use App\Models\User;

class StockMovementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('ProductView');
    }

    public function view(User $user, StockMovement $stockMovement): bool
    {
        return $user->hasPermissionTo('ProductView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('StockMove');
    }

    public function update(User $user, StockMovement $stockMovement): bool
    {
        return $user->hasPermissionTo('StockMove');
    }

    public function delete(User $user, StockMovement $stockMovement): bool
    {
        return $user->hasPermissionTo('StockAdjust');
    }
}
