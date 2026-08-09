<?php

namespace App\Modules\Invoicing\Filament\Resources\TaxRates\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Invoicing\Filament\Resources\TaxRates\TaxRateResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTaxRate extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = TaxRateResource::class;
}
