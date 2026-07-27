<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Filament\Resources\Projects\ProjectResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProject extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = ProjectResource::class;
}
