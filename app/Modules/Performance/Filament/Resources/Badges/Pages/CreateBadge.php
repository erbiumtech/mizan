<?php

namespace App\Modules\Performance\Filament\Resources\Badges\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Performance\Filament\Resources\Badges\BadgeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBadge extends CreateRecord
{
    use RedirectsToIndex;

    protected static string $resource = BadgeResource::class;
}
