<?php

namespace App\Modules\Core\Filament\Platform\Resources\ActivityLogs\Pages;

use App\Modules\Core\Filament\Platform\Resources\ActivityLogs\PlatformActivityLogResource;
use Filament\Resources\Pages\ViewRecord;

class ViewPlatformActivityLog extends ViewRecord
{
    protected static string $resource = PlatformActivityLogResource::class;
}
