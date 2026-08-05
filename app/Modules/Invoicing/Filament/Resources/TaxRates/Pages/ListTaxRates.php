<?php

namespace App\Modules\Invoicing\Filament\Resources\TaxRates\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Invoicing\Filament\Resources\TaxRates\TaxRateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTaxRates extends ListRecords
{
    protected static string $resource = TaxRateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('tax-rates', 'Tax Rates: Help'),
            CreateAction::make(),
        ];
    }
}
