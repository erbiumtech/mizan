<?php

namespace App\Modules\Performance\Filament\Resources\TaskOccurrences\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Performance\Filament\Resources\TaskOccurrences\TaskOccurrenceResource;
use Filament\Resources\Pages\ListRecords;

class ListTaskOccurrences extends ListRecords
{
    protected static string $resource = TaskOccurrenceResource::class;

    protected function getHeaderActions(): array
    {
        return [HelpAction::make('task-occurrences', 'Task Occurrences: Help')];
    }
}
