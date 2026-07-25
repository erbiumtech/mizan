<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Concerns\InteractsWithCustomFields;
use App\Filament\Resources\Invoices\InvoiceResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditInvoice extends EditRecord
{
    use InteractsWithCustomFields;

    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
