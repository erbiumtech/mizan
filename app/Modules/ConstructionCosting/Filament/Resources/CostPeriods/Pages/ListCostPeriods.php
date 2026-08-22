<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\CostPeriods\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\ConstructionCosting\Filament\Resources\CostPeriods\CostPeriodResource;
use Filament\Resources\Pages\ListRecords;

class ListCostPeriods extends ListRecords
{
    protected static string $resource = CostPeriodResource::class;

    protected function getHeaderActions(): array
    {
        return [HelpAction::make('construction-reconciliation', 'Cost periods and posting: Help')];
    }
}
