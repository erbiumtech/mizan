<?php

namespace App\Modules\Accounting\Policies;

use App\Modules\Accounting\Models\FixedAsset;
use App\Models\User;

class FixedAssetPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('FixedAssetView');
    }

    public function view(User $user, FixedAsset $asset): bool
    {
        return $user->hasPermissionTo('FixedAssetView');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('FixedAssetCreate');
    }

    public function update(User $user, FixedAsset $asset): bool
    {
        // Disposed assets are frozen.
        return $user->hasPermissionTo('FixedAssetUpdate')
            && $asset->status !== FixedAsset::STATUS_DISPOSED;
    }

    public function delete(User $user, FixedAsset $asset): bool
    {
        // Only assets with no ledger history may be deleted.
        return $user->hasPermissionTo('FixedAssetDelete')
            && ! $asset->journalEntries()->exists();
    }

    public function depreciate(User $user, FixedAsset $asset): bool
    {
        return $user->hasPermissionTo('FixedAssetDepreciate')
            && $asset->status === FixedAsset::STATUS_ACTIVE;
    }

    public function dispose(User $user, FixedAsset $asset): bool
    {
        return $user->hasPermissionTo('FixedAssetDispose')
            && $asset->status !== FixedAsset::STATUS_DISPOSED;
    }
}
