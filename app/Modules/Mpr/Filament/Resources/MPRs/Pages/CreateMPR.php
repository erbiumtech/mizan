<?php

namespace App\Modules\Mpr\Filament\Resources\MPRs\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Mpr\Filament\Resources\MPRs\MPRResource;
use Filament\Resources\Pages\CreateRecord;

class CreateMPR extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = MPRResource::class;
}
