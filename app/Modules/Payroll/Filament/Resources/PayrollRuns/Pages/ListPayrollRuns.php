<?php

namespace App\Modules\Payroll\Filament\Resources\PayrollRuns\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Payroll\Filament\Resources\PayrollRuns\PayrollRunResource;
use Filament\Resources\Pages\ListRecords;

class ListPayrollRuns extends ListRecords
{
    protected static string $resource = PayrollRunResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('payroll-runs', 'Payroll Runs: Help'),
        ];
    }
}
