<?php

namespace App\Modules\Core\Filament\Platform\Resources\SitePages\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Core\Filament\Platform\Resources\SitePages\SitePageResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSitePage extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = SitePageResource::class;
}
