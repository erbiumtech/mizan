<?php

namespace App\Modules\Accounting\Filament\Resources\JournalEntries\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Accounting\Filament\Resources\JournalEntries\JournalEntryResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditJournalEntry extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = JournalEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
