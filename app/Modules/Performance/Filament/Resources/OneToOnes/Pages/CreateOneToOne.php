<?php

namespace App\Modules\Performance\Filament\Resources\OneToOnes\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Performance\Filament\Resources\OneToOnes\OneToOneResource;
use Filament\Resources\Pages\CreateRecord;

class CreateOneToOne extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = OneToOneResource::class;
}
