<?php

namespace App\Filament\Resources\Payslips\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Filament\Resources\Payslips\PayslipResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPayslip extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = PayslipResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
