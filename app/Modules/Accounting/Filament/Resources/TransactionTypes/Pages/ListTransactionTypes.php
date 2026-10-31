<?php

namespace App\Modules\Accounting\Filament\Resources\TransactionTypes\Pages;

use App\Filament\Concerns\OpensRecordsInSheets;
use App\Filament\Support\HelpAction;
use App\Modules\Accounting\Filament\Resources\TransactionTypes\TransactionTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTransactionTypes extends ListRecords
{
    use OpensRecordsInSheets;

    protected static string $resource = TransactionTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('transaction-types', 'Transaction Types: Help'),
            CreateAction::make(),
        ];
    }
}
