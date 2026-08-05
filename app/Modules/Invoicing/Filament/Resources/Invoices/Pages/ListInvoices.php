<?php

namespace App\Modules\Invoicing\Filament\Resources\Invoices\Pages;

use App\Filament\Concerns\HasSavedViews;
use App\Filament\Support\HelpAction;
use App\Modules\Invoicing\Filament\Resources\Invoices\InvoiceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListInvoices extends ListRecords
{
    use HasSavedViews;

    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('invoices', 'Invoices: Help'),
            CreateAction::make(),
            $this->saveViewAction(),
        ];
    }
}
