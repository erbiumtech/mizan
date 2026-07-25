<?php

namespace App\Filament\Resources\AnnualTaxes\Pages;

use App\Filament\Resources\AnnualTaxes\AnnualTaxResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAnnualTaxes extends ListRecords
{
    protected static string $resource = AnnualTaxResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
