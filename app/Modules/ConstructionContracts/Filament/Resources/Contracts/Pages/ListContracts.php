<?php

namespace App\Modules\ConstructionContracts\Filament\Resources\Contracts\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\ConstructionContracts\Filament\Resources\Contracts\ContractResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListContracts extends ListRecords
{
    protected static string $resource = ContractResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('construction-contracts', 'Contracts: Help'),
            CreateAction::make(),
        ];
    }
}
