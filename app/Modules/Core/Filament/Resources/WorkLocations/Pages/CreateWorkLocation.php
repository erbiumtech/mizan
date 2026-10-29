<?php

namespace App\Modules\Core\Filament\Resources\WorkLocations\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Core\Filament\Resources\WorkLocations\WorkLocationResource;
use Filament\Resources\Pages\CreateRecord;

class CreateWorkLocation extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = WorkLocationResource::class;
}
