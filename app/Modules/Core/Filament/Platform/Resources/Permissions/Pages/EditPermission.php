<?php

namespace App\Modules\Core\Filament\Platform\Resources\Permissions\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Core\Filament\Platform\Resources\Permissions\PermissionResource;
use App\Support\PermissionCache;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPermission extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = PermissionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                // Deleting matters as much as renaming: a company still holding the deleted
                // name in its cached list keeps granting it.
                ->after(fn () => PermissionCache::flushEverywhere()),
        ];
    }

    /** See CreatePermission for why spatie's own invalidation cannot reach the companies. */
    protected function afterSave(): void
    {
        PermissionCache::flushEverywhere();
    }
}
