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
}
