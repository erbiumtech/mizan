<?php

namespace App\Modules\Employees\Filament\Resources\EmployeeSettings\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Employees\Filament\Resources\EmployeeSettings\EmployeeSettingResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEmployeeSettings extends ListRecords
{
    protected static string $resource = EmployeeSettingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('employee-settings', 'Employee Settings: Help'),
            CreateAction::make(),
        ];
    }
}
