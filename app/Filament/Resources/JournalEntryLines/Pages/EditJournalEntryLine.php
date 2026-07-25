<?php

namespace App\Filament\Resources\JournalEntryLines\Pages;

use App\Filament\Resources\JournalEntryLines\JournalEntryLineResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditJournalEntryLine extends EditRecord
{
    protected static string $resource = JournalEntryLineResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
