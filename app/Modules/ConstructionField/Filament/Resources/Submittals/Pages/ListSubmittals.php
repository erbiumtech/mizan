<?php

namespace App\Modules\ConstructionField\Filament\Resources\Submittals\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\ConstructionField\Filament\Resources\Submittals\SubmittalResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSubmittals extends ListRecords
{
    protected static string $resource = SubmittalResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('construction-submittals', 'Submittals: Help'),
            CreateAction::make(),
        ];
    }
}
