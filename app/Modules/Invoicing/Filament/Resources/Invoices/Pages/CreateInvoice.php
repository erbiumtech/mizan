<?php

namespace App\Modules\Invoicing\Filament\Resources\Invoices\Pages;

use App\Filament\Concerns\InteractsWithCustomFields;
use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Invoicing\Filament\Resources\Invoices\InvoiceResource;
use Filament\Resources\Pages\CreateRecord;

class CreateInvoice extends CreateRecord
{
    use InteractsWithCustomFields, RedirectsToIndex;

    protected static string $resource = InvoiceResource::class;

    /**
     * A new invoice opens on its own page rather than the list, unlike the rest of the panel
     * (RedirectsToIndex): the next thing anybody does with a new invoice is add its lines, issue it or
     * send it, and all three start there. "Create & create another" still returns a blank form.
     */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
