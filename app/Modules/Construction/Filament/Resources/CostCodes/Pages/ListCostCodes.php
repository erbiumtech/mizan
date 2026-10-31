<?php

namespace App\Modules\Construction\Filament\Resources\CostCodes\Pages;

use App\Filament\Concerns\OpensRecordsInSheets;
use App\Filament\Support\HelpAction;
use App\Modules\Construction\Filament\Resources\CostCodes\CostCodeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCostCodes extends ListRecords
{
    use OpensRecordsInSheets;

    protected static string $resource = CostCodeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('construction-cost-codes', 'Cost codes: Help'),
            CreateAction::make(),
        ];
    }
}
