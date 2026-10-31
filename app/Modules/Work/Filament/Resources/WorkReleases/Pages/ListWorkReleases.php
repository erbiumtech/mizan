<?php

namespace App\Modules\Work\Filament\Resources\WorkReleases\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Work\Filament\Resources\WorkReleases\WorkReleaseResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWorkReleases extends ListRecords
{
    protected static string $resource = WorkReleaseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            HelpAction::make('work-releases', 'Releases: Help'),
        ];
    }
}
