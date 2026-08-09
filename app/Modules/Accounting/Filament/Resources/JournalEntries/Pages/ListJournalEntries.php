<?php

namespace App\Modules\Accounting\Filament\Resources\JournalEntries\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Accounting\Filament\Resources\JournalEntries\JournalEntryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListJournalEntries extends ListRecords
{
    protected static string $resource = JournalEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('journal-entries', 'Journal Entries: Help'),
            CreateAction::make(),
        ];
    }
}
