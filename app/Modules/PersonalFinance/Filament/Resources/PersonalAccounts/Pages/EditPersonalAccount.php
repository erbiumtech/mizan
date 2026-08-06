<?php

namespace App\Modules\PersonalFinance\Filament\Resources\PersonalAccounts\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\PersonalFinance\Filament\Resources\PersonalAccounts\PersonalAccountResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPersonalAccount extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = PersonalAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
