<?php

namespace App\Modules\Lifecycle\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Lifecycle\Models\IssuedAsset;

class IssuedAssetPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('IssuedAssetView');
    }

    public function view(User $user, IssuedAsset $asset): bool
    {
        return $user->hasPermissionTo('IssuedAssetView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('IssuedAssetCreate');
    }

    public function update(User $user, IssuedAsset $asset): bool
    {
        return $user->hasPermissionTo('IssuedAssetUpdate');
    }

    /**
     * Never once returned.
     *
     * The returned row is the evidence the laptop came back. Deleting it is how a final
     * settlement comes to charge somebody for kit sitting on a shelf.
     */
    public function delete(User $user, IssuedAsset $asset): bool
    {
        return $user->hasPermissionTo('IssuedAssetDelete') && ! $asset->isReturned();
    }
}
