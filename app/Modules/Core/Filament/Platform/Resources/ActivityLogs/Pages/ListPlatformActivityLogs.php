<?php

namespace App\Modules\Core\Filament\Platform\Resources\ActivityLogs\Pages;

use App\Modules\Core\Filament\Platform\Resources\ActivityLogs\PlatformActivityLogResource;
use Filament\Resources\Pages\ListRecords;

class ListPlatformActivityLogs extends ListRecords
{
    protected static string $resource = PlatformActivityLogResource::class;
}
