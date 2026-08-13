<?php

namespace App\Modules\Recruitment\Filament\Resources\Applications\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Recruitment\Filament\Resources\Applications\ApplicationResource;
use Filament\Resources\Pages\CreateRecord;

class CreateApplication extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = ApplicationResource::class;
}
