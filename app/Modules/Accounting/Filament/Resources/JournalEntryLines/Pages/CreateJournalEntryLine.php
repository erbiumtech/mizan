<?php

namespace App\Modules\Accounting\Filament\Resources\JournalEntryLines\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Accounting\Filament\Resources\JournalEntryLines\JournalEntryLineResource;
use Filament\Resources\Pages\CreateRecord;

class CreateJournalEntryLine extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = JournalEntryLineResource::class;
}
