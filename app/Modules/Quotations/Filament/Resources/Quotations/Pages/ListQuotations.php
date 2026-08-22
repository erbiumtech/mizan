<?php

namespace App\Modules\Quotations\Filament\Resources\Quotations\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Quotations\Filament\Resources\Quotations\QuotationResource;
use Filament\Resources\Pages\ListRecords;

class ListQuotations extends ListRecords
{
    protected static string $resource = QuotationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('quotations', 'Quotes: Help'),
            \Filament\Actions\CreateAction::make(),
        ];
    }
}
