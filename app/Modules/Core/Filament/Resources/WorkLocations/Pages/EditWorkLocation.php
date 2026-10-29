<?php

namespace App\Modules\Core\Filament\Resources\WorkLocations\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Core\Filament\Resources\WorkLocations\WorkLocationResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditWorkLocation extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = WorkLocationResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
