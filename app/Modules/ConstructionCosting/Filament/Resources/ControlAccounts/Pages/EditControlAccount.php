<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\ControlAccounts\Pages;

use App\Modules\ConstructionCosting\Filament\Resources\ControlAccounts\ControlAccountResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditControlAccount extends EditRecord
{
    protected static string $resource = ControlAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
