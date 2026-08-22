<?php

namespace App\Modules\ConstructionContracts\Filament\Resources\Variations\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\ConstructionContracts\Filament\Resources\Variations\VariationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListVariations extends ListRecords
{
    protected static string $resource = VariationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('construction-variations', 'Variations: Help'),
            CreateAction::make(),
        ];
    }
}
