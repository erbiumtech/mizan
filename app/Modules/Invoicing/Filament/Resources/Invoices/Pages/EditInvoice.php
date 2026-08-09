<?php

namespace App\Modules\Invoicing\Filament\Resources\Invoices\Pages;

use App\Filament\Concerns\InteractsWithCustomFields;
use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Invoicing\Filament\Resources\Invoices\InvoiceResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditInvoice extends EditRecord
{
    use InteractsWithCustomFields, RedirectsToIndex;

    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
