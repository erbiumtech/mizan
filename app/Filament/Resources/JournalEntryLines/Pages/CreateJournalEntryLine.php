<?php

namespace App\Filament\Resources\JournalEntryLines\Pages;

use App\Filament\Resources\JournalEntryLines\JournalEntryLineResource;
use Filament\Resources\Pages\CreateRecord;

class CreateJournalEntryLine extends CreateRecord
{
    protected static string $resource = JournalEntryLineResource::class;
}
