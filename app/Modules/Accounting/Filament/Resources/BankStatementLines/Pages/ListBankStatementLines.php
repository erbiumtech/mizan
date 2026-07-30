<?php

namespace App\Modules\Accounting\Filament\Resources\BankStatementLines\Pages;

use App\Modules\Accounting\Filament\Resources\BankStatementLines\BankStatementLineResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBankStatementLines extends ListRecords
{
    protected static string $resource = BankStatementLineResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
