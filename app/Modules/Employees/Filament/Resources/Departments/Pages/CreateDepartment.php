<?php

namespace App\Modules\Employees\Filament\Resources\Departments\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Employees\Filament\Resources\Departments\DepartmentResource;
use Filament\Resources\Pages\CreateRecord;

class CreateDepartment extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = DepartmentResource::class;
}
