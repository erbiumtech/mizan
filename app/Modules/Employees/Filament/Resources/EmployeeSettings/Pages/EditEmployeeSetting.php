<?php

namespace App\Modules\Employees\Filament\Resources\EmployeeSettings\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Employees\Filament\Resources\EmployeeSettings\EmployeeSettingResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditEmployeeSetting extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = EmployeeSettingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
