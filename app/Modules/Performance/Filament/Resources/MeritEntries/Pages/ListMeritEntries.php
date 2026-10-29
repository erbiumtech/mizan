<?php

namespace App\Modules\Performance\Filament\Resources\MeritEntries\Pages;

use App\Modules\Performance\Filament\Resources\MeritEntries\MeritEntryResource;
use Filament\Resources\Pages\ListRecords;

class ListMeritEntries extends ListRecords
{
    protected static string $resource = MeritEntryResource::class;
}
