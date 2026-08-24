<?php

namespace App\Modules\ConstructionField\Filament\Resources\Activities\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\ConstructionField\Filament\Resources\Activities\ActivityResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListActivities extends ListRecords
{
    protected static string $resource = ActivityResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('construction-programme', 'Programme: Help'),
            CreateAction::make(),
        ];
    }
}
