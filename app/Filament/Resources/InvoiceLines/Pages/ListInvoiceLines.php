<?php

namespace App\Filament\Resources\InvoiceLines\Pages;

use App\Filament\Resources\InvoiceLines\InvoiceLineResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListInvoiceLines extends ListRecords
{
    protected static string $resource = InvoiceLineResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
