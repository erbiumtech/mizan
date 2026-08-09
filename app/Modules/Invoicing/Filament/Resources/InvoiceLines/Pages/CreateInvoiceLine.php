<?php

namespace App\Modules\Invoicing\Filament\Resources\InvoiceLines\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Invoicing\Filament\Resources\InvoiceLines\InvoiceLineResource;
use Filament\Resources\Pages\CreateRecord;

class CreateInvoiceLine extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = InvoiceLineResource::class;
}
