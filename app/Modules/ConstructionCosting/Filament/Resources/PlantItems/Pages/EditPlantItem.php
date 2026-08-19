<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\PlantItems\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Filament\Support\HelpAction;
use App\Modules\ConstructionCosting\Filament\Resources\PlantItems\PlantItemResource;
use Filament\Resources\Pages\EditRecord;

class EditPlantItem extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = PlantItemResource::class;

    protected function getHeaderActions(): array
    {
        return [HelpAction::make('construction-plant', 'Plant: Help')];
    }
}
