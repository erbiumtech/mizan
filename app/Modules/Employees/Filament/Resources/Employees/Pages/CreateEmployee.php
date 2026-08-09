<?php

namespace App\Modules\Employees\Filament\Resources\Employees\Pages;

use App\Filament\Concerns\InteractsWithCustomFields;
use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Employees\Filament\Resources\Employees\EmployeeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateEmployee extends CreateRecord
{
    use InteractsWithCustomFields, RedirectsToIndex;

    protected static string $resource = EmployeeResource::class;
}
