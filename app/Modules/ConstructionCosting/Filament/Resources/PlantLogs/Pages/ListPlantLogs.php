<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\PlantLogs\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\ConstructionCosting\Filament\Resources\PlantLogs\PlantLogResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPlantLogs extends ListRecords
{
    protected static string $resource = PlantLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('construction-plant-logs', 'Plant logs: Help'),
            CreateAction::make(),
        ];
    }
}
