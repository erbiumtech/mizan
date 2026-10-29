<?php

namespace App\Modules\Performance\Filament\Resources\Badges\Pages;

use App\Modules\Performance\Filament\Resources\Badges\BadgeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBadges extends ListRecords
{
    protected static string $resource = BadgeResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
