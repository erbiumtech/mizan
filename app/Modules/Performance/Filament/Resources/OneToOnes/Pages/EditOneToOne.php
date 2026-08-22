<?php

namespace App\Modules\Performance\Filament\Resources\OneToOnes\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Performance\Filament\Resources\OneToOnes\OneToOneResource;
use Filament\Resources\Pages\EditRecord;

class EditOneToOne extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = OneToOneResource::class;
}
