<?php

namespace App\Modules\Support\Filament\Resources\TicketCategories\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Support\Filament\Resources\TicketCategories\TicketCategoryResource;
use Filament\Resources\Pages\EditRecord;

class EditTicketCategory extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = TicketCategoryResource::class;

    protected function getHeaderActions(): array
    {
        // The policy allows it; a category with tickets against it is better switched off, which
        // the form says. Deleting one leaves those tickets with no category rather than removing
        // them — the column is nullable on purpose.
        return [\Filament\Actions\DeleteAction::make()];
    }
}
