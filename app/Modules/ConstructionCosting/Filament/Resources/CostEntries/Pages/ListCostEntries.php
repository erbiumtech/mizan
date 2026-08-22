<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\CostEntries\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\ConstructionCosting\Filament\Resources\CostEntries\CostEntryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCostEntries extends ListRecords
{
    protected static string $resource = CostEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('construction-job-cost', 'Job cost: Help'),
            CreateAction::make(),
        ];
    }
}
