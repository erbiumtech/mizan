<?php

namespace App\Modules\Work\Filament\Resources\WorkSprints\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Work\Filament\Resources\WorkSprints\WorkSprintResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWorkSprints extends ListRecords
{
    protected static string $resource = WorkSprintResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            HelpAction::make('work-sprints', 'Sprints: Help'),
        ];
    }
}
