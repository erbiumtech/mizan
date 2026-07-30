<?php

namespace App\Modules\Employees\Filament\Resources\EmployeeSettings\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Employees\Filament\Resources\EmployeeSettings\EmployeeSettingResource;
use Filament\Resources\Pages\CreateRecord;

class CreateEmployeeSetting extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = EmployeeSettingResource::class;
}
