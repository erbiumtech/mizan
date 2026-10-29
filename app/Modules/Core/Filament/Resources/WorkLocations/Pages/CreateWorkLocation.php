<?php

namespace App\Modules\Core\Filament\Resources\WorkLocations\Pages;

use App\Modules\Core\Filament\Resources\WorkLocations\WorkLocationResource;
use Filament\Resources\Pages\CreateRecord;

class CreateWorkLocation extends CreateRecord
{
    protected static string $resource = WorkLocationResource::class;
}
