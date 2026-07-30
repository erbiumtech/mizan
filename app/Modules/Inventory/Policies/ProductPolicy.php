<?php

namespace App\Modules\Inventory\Policies;

use App\Modules\Inventory\Models\Product;
use App\Models\User;

class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('ProductView');
    }

    public function view(User $user, Product $product): bool
    {
        return $user->hasPermissionTo('ProductView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('ProductCreate');
    }

    public function update(User $user, Product $product): bool
    {
        return $user->hasPermissionTo('ProductUpdate');
    }

    public function delete(User $user, Product $product): bool
    {
        if (! $user->hasPermissionTo('ProductDelete')) {
            return false;
        }

        // Products with movement history must not be deleted.
        return ! $product->movements()->exists();
    }
}
