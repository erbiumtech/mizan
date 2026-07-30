<?php

namespace App\Modules\Accounting\Filament\Resources\JournalEntryLines\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Accounting\Filament\Resources\JournalEntryLines\JournalEntryLineResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditJournalEntryLine extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = JournalEntryLineResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
