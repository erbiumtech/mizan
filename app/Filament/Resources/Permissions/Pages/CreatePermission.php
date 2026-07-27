<?php

namespace App\Filament\Resources\Permissions\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Filament\Resources\Permissions\PermissionResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePermission extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = PermissionResource::class;
}
