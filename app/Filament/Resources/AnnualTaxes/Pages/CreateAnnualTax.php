<?php

namespace App\Filament\Resources\AnnualTaxes\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Filament\Resources\AnnualTaxes\AnnualTaxResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAnnualTax extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = AnnualTaxResource::class;
}
