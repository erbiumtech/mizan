<?php

namespace App\Filament\Resources\InvoiceLines\Pages;

use App\Filament\Resources\InvoiceLines\InvoiceLineResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditInvoiceLine extends EditRecord
{
    protected static string $resource = InvoiceLineResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
