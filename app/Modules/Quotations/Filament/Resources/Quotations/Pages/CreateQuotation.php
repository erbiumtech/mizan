<?php

namespace App\Modules\Quotations\Filament\Resources\Quotations\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Quotations\Filament\Resources\Quotations\QuotationResource;
use Filament\Resources\Pages\CreateRecord;

class CreateQuotation extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = QuotationResource::class;

    /** The stored totals, from the lines just saved — until Send they were 0.00 on the list. */
    protected function afterCreate(): void
    {
        $this->record->recalculate();
    }
}
