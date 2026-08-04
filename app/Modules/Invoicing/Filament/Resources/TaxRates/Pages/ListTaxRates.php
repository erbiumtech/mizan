<?php

namespace App\Modules\Invoicing\Filament\Resources\TaxRates\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Invoicing\Filament\Resources\TaxRates\TaxRateResource;
use Filament\Resources\Pages\ListRecords;

class ListTaxRates extends ListRecords
{
    protected static string $resource = TaxRateResource::class;

    protected function getHeaderActions(): array
    {
        return [\Filament\Actions\CreateAction::make()];
    }
}
