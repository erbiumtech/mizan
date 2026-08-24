<?php

namespace App\Modules\ConstructionField\Filament\Resources\PunchLists\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\ConstructionField\Filament\Resources\PunchLists\PunchListResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPunchLists extends ListRecords
{
    protected static string $resource = PunchListResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('construction-punch-lists', 'Punch lists: Help'),
            CreateAction::make(),
        ];
    }
}
