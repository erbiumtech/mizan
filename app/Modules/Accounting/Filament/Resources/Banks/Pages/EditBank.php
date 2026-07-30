<?php

namespace App\Modules\Accounting\Filament\Resources\Banks\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Accounting\Filament\Resources\Banks\BankResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditBank extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = BankResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
