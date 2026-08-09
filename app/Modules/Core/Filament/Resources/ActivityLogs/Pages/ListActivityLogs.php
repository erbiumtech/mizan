<?php

namespace App\Modules\Core\Filament\Resources\ActivityLogs\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Core\Filament\Resources\ActivityLogs\ActivityLogResource;
use Filament\Resources\Pages\ListRecords;

class ListActivityLogs extends ListRecords
{
    protected static string $resource = ActivityLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('activity-logs', 'Activity Log: Help'),
        ];
    }
}
