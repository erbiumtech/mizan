<?php

namespace App\Modules\Payroll\Filament\Resources\AnnualTaxes\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Payroll\Filament\Resources\AnnualTaxes\AnnualTaxResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAnnualTax extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = AnnualTaxResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
