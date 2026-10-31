<?php

namespace App\Modules\Accounting\Filament\Resources\TransactionTypes\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

/** A transaction type read in the list's view sheet — docs/side-sheets.md. */
class TransactionTypeInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextEntry::make('name')->label(__('Name')),
            TextEntry::make('code')->label(__('Code'))->copyable(),
            TextEntry::make('account.name')->label(__('Default Account'))->placeholder('—'),
            IconEntry::make('is_active')->label(__('Active'))->boolean(),
            TextEntry::make('description')->label(__('Description'))->placeholder('—')->columnSpanFull(),
        ]);
    }
}
