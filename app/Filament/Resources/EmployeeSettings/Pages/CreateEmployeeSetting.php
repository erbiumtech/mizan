<?php

namespace App\Filament\Resources\EmployeeSettings\Pages;

use App\Filament\Resources\EmployeeSettings\EmployeeSettingResource;
use Filament\Resources\Pages\CreateRecord;

class CreateEmployeeSetting extends CreateRecord
{
    protected static string $resource = EmployeeSettingResource::class;
}
