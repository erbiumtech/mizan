<?php

namespace App\Modules\Core\Filament\Platform\Resources\ActivityLogs\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Core\Filament\Platform\Resources\ActivityLogs\PlatformActivityLogResource;
use Filament\Resources\Pages\ListRecords;

class ListPlatformActivityLogs extends ListRecords
{
    protected static string $resource = PlatformActivityLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('platform-activity-logs', 'Platform Activity Logs: Help'),
        ];
    }
}
