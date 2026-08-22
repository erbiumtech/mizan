<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\PlantItems\Pages;

use App\Modules\ConstructionCosting\Filament\Resources\PlantItems\PlantItemResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePlantItem extends CreateRecord
{
    protected static string $resource = PlantItemResource::class;

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
