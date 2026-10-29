<?php

namespace App\Modules\Lifecycle\Filament\Resources\EmploymentContracts\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Lifecycle\Filament\Resources\EmploymentContracts\EmploymentContractResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEmploymentContracts extends ListRecords
{
    protected static string $resource = EmploymentContractResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('employment-contracts', 'Employment Contracts: Help'),
            CreateAction::make(),
        ];
    }
}
