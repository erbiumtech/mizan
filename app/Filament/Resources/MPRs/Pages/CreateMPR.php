<?php

namespace App\Filament\Resources\MPRs\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Filament\Resources\MPRs\MPRResource;
use Filament\Resources\Pages\CreateRecord;

class CreateMPR extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = MPRResource::class;
}
