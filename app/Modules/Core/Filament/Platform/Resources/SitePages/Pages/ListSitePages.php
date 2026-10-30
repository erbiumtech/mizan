<?php

namespace App\Modules\Core\Filament\Platform\Resources\SitePages\Pages;

use App\Modules\Core\Filament\Platform\Resources\SitePages\SitePageResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSitePages extends ListRecords
{
    protected static string $resource = SitePageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
