<?php

namespace App\Modules\Accounting\Filament\Resources\BankStatements\Pages;

use App\Filament\Concerns\RedirectsToIndex;
use App\Modules\Accounting\Filament\Resources\BankStatements\BankStatementResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditBankStatement extends EditRecord
{
    use RedirectsToIndex;

    protected static string $resource = BankStatementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
