<?php

namespace App\Modules\Advances\Filament\Resources\Advances\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Advances\Filament\Resources\Advances\AdvanceResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAdvance extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = AdvanceResource::class;
}
