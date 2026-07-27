<?php

namespace App\Filament\Resources\InvoiceLines\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Filament\Resources\InvoiceLines\InvoiceLineResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditInvoiceLine extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = InvoiceLineResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
