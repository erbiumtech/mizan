<?php

namespace App\Modules\Core\Filament\Resources\Roles\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Core\Filament\Resources\Roles\Pages\Concerns\SyncsGroupedPermissions;
use App\Modules\Core\Filament\Resources\Roles\RoleResource;
use Filament\Resources\Pages\CreateRecord;

class CreateRole extends CreateRecord
{
    use RedirectsToIndex, SyncsGroupedPermissions;

    protected static string $resource = RoleResource::class;

    protected function afterCreate(): void
    {
        $this->syncGroupedPermissions();
    }
}
