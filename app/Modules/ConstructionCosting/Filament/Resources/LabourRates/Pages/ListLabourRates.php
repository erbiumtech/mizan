<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\LabourRates\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\ConstructionCosting\Filament\Resources\LabourRates\LabourRateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLabourRates extends ListRecords
{
    protected static string $resource = LabourRateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('construction-labour-rates', 'Labour rates: Help'),
            CreateAction::make(),
        ];
    }
}
