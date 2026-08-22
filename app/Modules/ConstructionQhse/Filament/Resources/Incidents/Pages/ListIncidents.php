<?php

namespace App\Modules\ConstructionQhse\Filament\Resources\Incidents\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\ConstructionQhse\Filament\Resources\Incidents\IncidentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListIncidents extends ListRecords
{
    protected static string $resource = IncidentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('construction-incidents', 'Incidents: Help'),
            // Labelled for what it mostly is. Most of what belongs in this register hurt nobody, and a button saying
            // "new incident" is a button people hesitate over.
            CreateAction::make()->label('Report something'),
        ];
    }
}
