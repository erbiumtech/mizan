<?php

namespace App\Modules\Crm\Filament\Resources\NextActions\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Crm\Filament\Resources\NextActions\NextActionResource;
use Filament\Resources\Pages\EditRecord;

class EditNextAction extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = NextActionResource::class;

    protected function getHeaderActions(): array
    {
        return [\Filament\Actions\DeleteAction::make()];
    }
}
