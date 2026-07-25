<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Concerns\InteractsWithCustomFields;
use App\Filament\Resources\Invoices\InvoiceResource;
use Filament\Resources\Pages\CreateRecord;

class CreateInvoice extends CreateRecord
{
    use InteractsWithCustomFields;

    protected static string $resource = InvoiceResource::class;
}
