<?php

namespace App\Modules\Accounting\Filament\Resources\JournalEntries\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Accounting\Filament\Resources\JournalEntries\JournalEntryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateJournalEntry extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = JournalEntryResource::class;
}
