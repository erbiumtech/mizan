<?php

namespace App\Modules\ConstructionQhse\Filament\Resources\Permits\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\ConstructionQhse\Filament\Resources\Permits\PermitResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPermits extends ListRecords
{
    protected static string $resource = PermitResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('construction-permits', 'Permits to work: Help'),
            CreateAction::make()->label('Request a permit'),
        ];
    }
}
