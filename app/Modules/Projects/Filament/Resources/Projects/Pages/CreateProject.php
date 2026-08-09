<?php

namespace App\Modules\Projects\Filament\Resources\Projects\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Projects\Filament\Resources\Projects\ProjectResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProject extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = ProjectResource::class;
}
