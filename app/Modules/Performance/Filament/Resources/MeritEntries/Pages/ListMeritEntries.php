<?php

namespace App\Modules\Performance\Filament\Resources\MeritEntries\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Performance\Filament\Resources\MeritEntries\MeritEntryResource;
use Filament\Resources\Pages\ListRecords;

class ListMeritEntries extends ListRecords
{
    protected static string $resource = MeritEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [HelpAction::make('merit-entries', 'Merit Entries: Help')];
    }
}
