<?php

namespace App\Modules\Payroll\Filament\Resources\SalaryStructures\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Payroll\Filament\Resources\SalaryStructures\SalaryStructureResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSalaryStructure extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = SalaryStructureResource::class;
}
