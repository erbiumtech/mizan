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
            // What other modules offer on a package — Payroll's "Fill from structure".
            ...\App\Support\ResourceContributions::headerActionsFor(static::class),
            DeleteAction::make(),
        ];
    }
}
