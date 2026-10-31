<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\PlantItems\Pages;

use App\Filament\Concerns\OpensRecordsInSheets;
use App\Filament\Support\HelpAction;
use App\Modules\ConstructionCosting\Filament\Resources\PlantItems\PlantItemResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPlantItems extends ListRecords
{
    use OpensRecordsInSheets;

    protected static string $resource = PlantItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('construction-plant', 'Plant: Help'),
            CreateAction::make(),
        ];
    }
}
