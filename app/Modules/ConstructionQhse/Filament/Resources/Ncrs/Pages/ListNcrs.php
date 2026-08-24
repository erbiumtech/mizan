<?php

namespace App\Modules\ConstructionQhse\Filament\Resources\Ncrs\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\ConstructionQhse\Filament\Resources\Ncrs\NcrResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListNcrs extends ListRecords
{
    protected static string $resource = NcrResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('construction-ncrs', 'Non-conformance: Help'),
            CreateAction::make(),
        ];
    }
}
