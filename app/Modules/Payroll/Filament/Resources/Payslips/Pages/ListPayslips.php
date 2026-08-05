<?php

namespace App\Modules\Payroll\Filament\Resources\Payslips\Pages;

use App\Filament\Concerns\HasSavedViews;
use App\Filament\Support\HelpAction;
use App\Modules\Payroll\Filament\Resources\Payslips\PayslipResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPayslips extends ListRecords
{
    use HasSavedViews;

    protected static string $resource = PayslipResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('payslips', 'Payslips: Help'),
            CreateAction::make(),
            $this->saveViewAction(),
        ];
    }
}
