<?php

namespace App\Modules\Core\Filament\Platform\Resources\Permissions\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Core\Filament\Platform\Resources\Permissions\PermissionResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePermission extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = PermissionResource::class;
}
