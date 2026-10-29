<?php

namespace App\Modules\Advances\Filament\Resources\AdvanceTypes\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Advances\Filament\Resources\AdvanceTypes\AdvanceTypeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAdvanceType extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = AdvanceTypeResource::class;
}
