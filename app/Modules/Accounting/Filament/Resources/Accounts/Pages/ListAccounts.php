<?php

namespace App\Modules\Accounting\Filament\Resources\Accounts\Pages;

use App\Filament\Concerns\OpensRecordsInSheets;
use App\Filament\Support\HelpAction;
use App\Modules\Accounting\Filament\Resources\Accounts\AccountResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAccounts extends ListRecords
{
    use OpensRecordsInSheets;

    protected static string $resource = AccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('accounts', 'Chart of Accounts: Help'),
            CreateAction::make(),
        ];
    }
}
