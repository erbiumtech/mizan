<?php

namespace App\Modules\Core\Filament\Resources\WorkLocations\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Core\Filament\Resources\WorkLocations\WorkLocationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWorkLocations extends ListRecords
{
    protected static string $resource = WorkLocationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('locations', 'Locations: Help'),
            CreateAction::make(),
        ];
    }
}
