<?php

namespace App\Modules\Payroll\Filament\Resources\SalarySlabs\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Payroll\Filament\Resources\SalarySlabs\SalarySlabResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSalarySlab extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = SalarySlabResource::class;
}
