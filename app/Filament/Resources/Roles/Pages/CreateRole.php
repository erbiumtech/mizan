<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\Pages\Concerns\SyncsGroupedPermissions;
use App\Filament\Resources\Roles\RoleResource;
use Filament\Resources\Pages\CreateRecord;

class CreateRole extends CreateRecord
{
    use SyncsGroupedPermissions;

    protected static string $resource = RoleResource::class;

    protected function afterCreate(): void
    {
        $this->syncGroupedPermissions();
    }
}
