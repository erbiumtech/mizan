<?php

namespace App\Modules\Advances\Filament\Resources\AdvanceTypes\Pages;

use App\Modules\Advances\Filament\Resources\AdvanceTypes\AdvanceTypeResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAdvanceType extends EditRecord
{
    protected static string $resource = AdvanceTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
