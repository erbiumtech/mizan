<?php

namespace App\Modules\Recruitment\Filament\Resources\Applications\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Recruitment\Filament\Resources\Applications\ApplicationResource;
use Filament\Resources\Pages\ListRecords;

class ListApplications extends ListRecords
{
    protected static string $resource = ApplicationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('applications', 'Applications: Help'),
            \Filament\Actions\CreateAction::make()->label('Record an application'),
        ];
    }
}
