<?php

namespace App\Filament\Resources\JournalEntryLines\Pages;

use App\Filament\Resources\JournalEntryLines\JournalEntryLineResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListJournalEntryLines extends ListRecords
{
    protected static string $resource = JournalEntryLineResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
