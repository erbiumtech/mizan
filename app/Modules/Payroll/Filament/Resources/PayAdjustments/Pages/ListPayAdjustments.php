<?php

namespace App\Modules\Payroll\Filament\Resources\PayAdjustments\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Payroll\Filament\Resources\PayAdjustments\PayAdjustmentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPayAdjustments extends ListRecords
{
    protected static string $resource = PayAdjustmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('pay-adjustments', 'Pay Adjustments: Help'),
            CreateAction::make(),
        ];
    }
}
