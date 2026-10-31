<?php

namespace App\Modules\Employees\Filament\Resources\Departments\Pages;

use App\Filament\Actions\ReplicateRecordAction;
use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Employees\Filament\Resources\Departments\DepartmentResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditDepartment extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = DepartmentResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make(), ReplicateRecordAction::make()];
    }
}
