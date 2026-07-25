<?php

namespace App\Filament\Resources\MPRs\Pages;

use App\Filament\Resources\MPRs\MPRResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditMPR extends EditRecord
{
    protected static string $resource = MPRResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
