<?php

namespace App\Modules\Payroll\Filament\Resources\SalarySlabs\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Payroll\Filament\Resources\SalarySlabs\SalarySlabResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSalarySlabs extends ListRecords
{
    protected static string $resource = SalarySlabResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('salary-slabs', 'Salary Slabs: Help'),
            CreateAction::make(),
        ];
    }
}
