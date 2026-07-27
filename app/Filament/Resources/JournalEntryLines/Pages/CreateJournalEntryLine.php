<?php

namespace App\Filament\Resources\JournalEntryLines\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Filament\Resources\JournalEntryLines\JournalEntryLineResource;
use Filament\Resources\Pages\CreateRecord;

class CreateJournalEntryLine extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = JournalEntryLineResource::class;
}
