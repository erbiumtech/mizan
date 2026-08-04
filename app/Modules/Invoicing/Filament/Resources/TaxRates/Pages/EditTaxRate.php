<?php

namespace App\Modules\Invoicing\Filament\Resources\TaxRates\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Invoicing\Filament\Resources\TaxRates\TaxRateResource;
use Filament\Resources\Pages\EditRecord;

class EditTaxRate extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = TaxRateResource::class;

    protected function getHeaderActions(): array
    {
        return [\Filament\Actions\DeleteAction::make()];
    }
}
