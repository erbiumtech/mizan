<?php

namespace App\Modules\Accounting\Filament\Resources\Currencies\Pages;

use App\Filament\Concerns\OpensRecordsInSheets;
use App\Filament\Support\HelpAction;
use App\Modules\Accounting\Filament\Resources\Currencies\CurrencyResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCurrencies extends ListRecords
{
    use OpensRecordsInSheets;

    protected static string $resource = CurrencyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('currencies', 'Currencies: Help'),
            CreateAction::make(),
        ];
    }
}
