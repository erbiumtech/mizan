<?php

namespace App\Modules\ConstructionQhse\Filament\Resources\SitePersonnel\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Filament\Support\HelpAction;
use App\Modules\ConstructionQhse\Filament\Resources\SitePersonnel\SitePersonnelResource;
use Filament\Resources\Pages\EditRecord;

class EditSitePersonnel extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = SitePersonnelResource::class;

    protected function getHeaderActions(): array
    {
        return [HelpAction::make('construction-site-personnel', 'Site personnel: Help')];
    }
}
