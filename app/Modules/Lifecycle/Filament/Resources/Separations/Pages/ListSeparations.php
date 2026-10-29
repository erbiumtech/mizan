<?php

namespace App\Modules\Lifecycle\Filament\Resources\Separations\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Lifecycle\Filament\Resources\Separations\SeparationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSeparations extends ListRecords
{
    protected static string $resource = SeparationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('separations', 'Separations: Help'),
            CreateAction::make(),
        ];
    }
}
