<?php

namespace App\Modules\Payroll\Filament\Resources\PayrollRuns\Pages;

use App\Modules\Payroll\Filament\Resources\PayrollRuns\PayrollRunResource;
use Filament\Resources\Pages\ListRecords;

class ListPayrollRuns extends ListRecords
{
    protected static string $resource = PayrollRunResource::class;
}
