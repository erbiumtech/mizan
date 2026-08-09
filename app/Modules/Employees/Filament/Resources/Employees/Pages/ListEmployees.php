<?php

namespace App\Modules\Employees\Filament\Resources\Employees\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Employees\Filament\Resources\Employees\EmployeeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEmployees extends ListRecords
{
    protected static string $resource = EmployeeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('employees', 'Employees: Help'),
            CreateAction::make(),
        ];
    }
}
