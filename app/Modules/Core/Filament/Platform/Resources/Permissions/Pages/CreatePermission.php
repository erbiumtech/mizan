<?php

namespace App\Modules\Core\Filament\Platform\Resources\Permissions\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Core\Filament\Platform\Resources\Permissions\PermissionResource;
use App\Support\PermissionCache;
use Filament\Resources\Pages\CreateRecord;

class CreatePermission extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = PermissionResource::class;

    /**
     * A permission created here is invisible to every company until their cached list expires.
     *
     * This screen is on the platform panel, which has no company, so spatie invalidates the
     * copy nobody reads and leaves each company's alone — and a policy checking a name that is
     * in the table but not in that copy throws rather than denying, taking the panel down. See
     * PermissionCache.
     */
    protected function afterCreate(): void
    {
        PermissionCache::flushEverywhere();
    }
}
