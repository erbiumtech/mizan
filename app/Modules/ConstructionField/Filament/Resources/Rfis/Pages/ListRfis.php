<?php

namespace App\Modules\ConstructionField\Filament\Resources\Rfis\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\ConstructionField\Filament\Resources\Rfis\RfiResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRfis extends ListRecords
{
    protected static string $resource = RfiResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('construction-rfis', 'RFIs: Help'),
            CreateAction::make(),
        ];
    }
}
