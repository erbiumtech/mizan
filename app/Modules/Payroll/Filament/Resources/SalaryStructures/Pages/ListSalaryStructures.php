<?php

namespace App\Modules\Payroll\Filament\Resources\SalaryStructures\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Payroll\Filament\Resources\SalaryStructures\SalaryStructureResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSalaryStructures extends ListRecords
{
    protected static string $resource = SalaryStructureResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('salary-structures', 'Salary Structures: Help'),
            CreateAction::make(),
        ];
    }
}
