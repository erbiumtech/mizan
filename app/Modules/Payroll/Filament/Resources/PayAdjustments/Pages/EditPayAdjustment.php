<?php

namespace App\Modules\Payroll\Filament\Resources\PayAdjustments\Pages;

use App\Modules\Payroll\Filament\Resources\PayAdjustments\PayAdjustmentResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPayAdjustment extends EditRecord
{
    protected static string $resource = PayAdjustmentResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
