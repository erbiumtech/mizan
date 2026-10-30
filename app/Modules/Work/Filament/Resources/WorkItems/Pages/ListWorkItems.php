<?php

namespace App\Modules\Work\Filament\Resources\WorkItems\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Work\Filament\Resources\WorkItems\WorkItemResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWorkItems extends ListRecords
{
    protected static string $resource = WorkItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            HelpAction::make('work-items', 'Work Items: Help'),
        ];
    }
}
