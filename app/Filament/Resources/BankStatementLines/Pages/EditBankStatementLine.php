<?php

namespace App\Filament\Resources\BankStatementLines\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Filament\Resources\BankStatementLines\BankStatementLineResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditBankStatementLine extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = BankStatementLineResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
