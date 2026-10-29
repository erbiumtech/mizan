<?php

namespace App\Modules\Performance\Filament\Resources\TaskOccurrences\Pages;

use App\Modules\Performance\Filament\Resources\TaskOccurrences\TaskOccurrenceResource;
use Filament\Resources\Pages\ListRecords;

class ListTaskOccurrences extends ListRecords
{
    protected static string $resource = TaskOccurrenceResource::class;
}
