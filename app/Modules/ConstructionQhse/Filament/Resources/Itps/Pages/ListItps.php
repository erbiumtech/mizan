<?php

namespace App\Modules\ConstructionQhse\Filament\Resources\Itps\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\ConstructionQhse\Filament\Resources\Itps\ItpResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListItps extends ListRecords
{
    protected static string $resource = ItpResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('construction-itps', 'ITPs and inspections: Help'),
            CreateAction::make(),
        ];
    }
}
