<?php

namespace App\Filament\Resources\InvoiceLines\Pages;

use App\Filament\Resources\InvoiceLines\InvoiceLineResource;
use Filament\Resources\Pages\CreateRecord;

class CreateInvoiceLine extends CreateRecord
{
    protected static string $resource = InvoiceLineResource::class;
}
