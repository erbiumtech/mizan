<?php

namespace App\Modules\ConstructionField\Filament\Resources\DailyLogs\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\ConstructionField\Filament\Resources\DailyLogs\DailyLogResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDailyLogs extends ListRecords
{
    protected static string $resource = DailyLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('construction-site-diary', 'Site diary: Help'),
            CreateAction::make(),
        ];
    }
}
