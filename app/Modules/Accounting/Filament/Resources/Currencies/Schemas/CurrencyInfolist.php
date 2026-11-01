<?php

namespace App\Modules\Accounting\Filament\Resources\Currencies\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

/** A currency read in the list's view sheet — docs/side-sheets.md. */
class CurrencyInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            TextEntry::make('code')->label(__('Code'))->copyable(),
            TextEntry::make('name')->label(__('Name')),
            TextEntry::make('symbol')->label(__('Symbol'))->placeholder('—'),
            TextEntry::make('decimals')->label(__('Decimals')),
            IconEntry::make('is_active')->label(__('In use'))->boolean(),
        ]);
    }
}
