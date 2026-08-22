<?php

namespace App\Modules\Construction\Filament\Resources\Jobs\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Construction\Filament\Resources\Jobs\JobResource;
use Filament\Resources\Pages\CreateRecord;

class CreateJob extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = JobResource::class;
}
