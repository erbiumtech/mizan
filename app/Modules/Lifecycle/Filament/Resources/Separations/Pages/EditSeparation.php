<?php

namespace App\Modules\Lifecycle\Filament\Resources\Separations\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Lifecycle\Filament\Resources\Separations\SeparationResource;
use Filament\Resources\Pages\EditRecord;

class EditSeparation extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = SeparationResource::class;
}
