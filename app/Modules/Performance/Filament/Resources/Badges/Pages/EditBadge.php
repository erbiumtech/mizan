<?php

namespace App\Modules\Performance\Filament\Resources\Badges\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Performance\Filament\Resources\Badges\BadgeResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditBadge extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = BadgeResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
