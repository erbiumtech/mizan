<?php

namespace App\Filament\Resources\AnnualTaxes\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Filament\Resources\AnnualTaxes\AnnualTaxResource;
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
