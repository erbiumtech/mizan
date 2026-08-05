<?php

namespace App\Modules\Core\Filament\Platform\Resources\Users\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Core\Filament\Platform\Resources\Users\PlatformUserResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPlatformUser extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = PlatformUserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
