<?php

namespace App\Modules\Performance\Filament\Resources\Badges\Pages;

use App\Modules\Performance\Filament\Resources\Badges\BadgeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBadge extends CreateRecord
{
    protected static string $resource = BadgeResource::class;
}
