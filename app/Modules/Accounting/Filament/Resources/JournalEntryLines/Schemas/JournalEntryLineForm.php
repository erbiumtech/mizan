<?php

namespace App\Modules\Accounting\Filament\Resources\JournalEntryLines\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class JournalEntryLineForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('journal_entry_id')
                    ->label(__('Journal Entry'))
                    ->relationship('journalEntry', 'id')
                    ->searchable()
                    ->preload()
                    ->required(),

                // Nova: relatableQueryUsing — is_active, allow_manual_entry, no children
                Select::make('account_id')
                    ->label(__('Account'))
                    ->relationship(
                        'account',
                        'name',
                        fn (Builder $query) => $query
                            ->where('is_active', true)
                            ->where('allow_manual_entry', true)
                            ->whereDoesntHave('children'),
                    )
                    ->searchable()
                    ->preload()
                    ->required(),

                TextInput::make('debit_amount')
                    ->label(__('Debit'))
                    ->numeric()
                    ->step(0.01)
                    ->minValue(0)
                    ->default(0)
                    ->required(),

                TextInput::make('credit_amount')
                    ->label(__('Credit'))
                    ->numeric()
                    ->step(0.01)
                    ->minValue(0)
                    ->default(0)
                    ->required(),

                TextInput::make('description')
                    ->nullable(),
            ]);
    }
}
