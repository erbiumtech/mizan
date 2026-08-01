<?php

namespace App\Modules\Advances\Filament\Resources\Advances\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Advances\Filament\Resources\Advances\AdvanceResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAdvance extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = AdvanceResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
