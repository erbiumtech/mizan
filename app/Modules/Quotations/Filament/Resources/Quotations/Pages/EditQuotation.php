<?php

namespace App\Modules\Quotations\Filament\Resources\Quotations\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Quotations\Filament\Resources\Quotations\QuotationResource;
use Filament\Resources\Pages\EditRecord;

class EditQuotation extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = QuotationResource::class;

    protected function getHeaderActions(): array
    {
        return [\Filament\Actions\DeleteAction::make()];
    }
}
