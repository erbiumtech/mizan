<?php

namespace App\Modules\Accounting\Filament\Resources\ChequeBooks\Pages;

use App\Filament\Concerns\OpensRecordsInSheets;
use App\Filament\Support\HelpAction;
use App\Modules\Accounting\Filament\Resources\ChequeBooks\ChequeBookResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListChequeBooks extends ListRecords
{
    use OpensRecordsInSheets;

    protected static string $resource = ChequeBookResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('cheque-books', 'Cheque Books: Help'),
            CreateAction::make(),
        ];
    }
}
