<?php

namespace App\Modules\Payroll\Filament\Resources\SalaryStructures\Pages;

use App\Modules\Payroll\Filament\Resources\SalaryStructures\SalaryStructureResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSalaryStructure extends CreateRecord
{
    protected static string $resource = SalaryStructureResource::class;
}
