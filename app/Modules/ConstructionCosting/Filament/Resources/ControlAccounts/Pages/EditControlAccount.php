<?php

namespace App\Modules\ConstructionCosting\Filament\Resources\ControlAccounts\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\ConstructionCosting\Filament\Resources\ControlAccounts\ControlAccountResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditControlAccount extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = ControlAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
