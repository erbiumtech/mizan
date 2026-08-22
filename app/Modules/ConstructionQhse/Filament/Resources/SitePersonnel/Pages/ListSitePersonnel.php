<?php

namespace App\Modules\ConstructionQhse\Filament\Resources\SitePersonnel\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\ConstructionQhse\Filament\Resources\SitePersonnel\SitePersonnelResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSitePersonnel extends ListRecords
{
    protected static string $resource = SitePersonnelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('construction-site-personnel', 'Site personnel: Help'),
            CreateAction::make()->label('Add somebody'),
        ];
    }
}
