<?php

namespace App\Modules\Accounting\Filament\Resources\BankStatements\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Accounting\Filament\Resources\BankStatements\BankStatementResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBankStatements extends ListRecords
{
    protected static string $resource = BankStatementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('bank-statements', 'Bank Statements: Help'),
            CreateAction::make(),
        ];
    }
}
