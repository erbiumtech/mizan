<?php

namespace App\Filament\Resources\AnnualTaxes\Pages;

use App\Filament\Resources\AnnualTaxes\AnnualTaxResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAnnualTax extends EditRecord
{
    protected static string $resource = AnnualTaxResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
