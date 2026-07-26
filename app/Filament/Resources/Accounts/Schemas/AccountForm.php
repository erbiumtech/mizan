<?php

namespace App\Filament\Resources\Accounts\Schemas;

use App\Models\Account;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class AccountForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                // Nova: Text code — required, max:20, unique on create + update-with-id.
                TextInput::make('code')
                    ->required()
                    ->maxLength(20)
                    ->unique(table: Account::class, column: 'code', ignoreRecord: true),

                // Nova: Text name — required, max:255.
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),

                // Nova: Select type — required.
                Select::make('type')
                    ->options([
                        'asset' => 'Asset',
                        'liability' => 'Liability',
                        'equity' => 'Equity',
                        'income' => 'Income',
                        'expense' => 'Expense',
                    ])
                    ->required(),

                // Nova: BelongsTo parent → Account — nullable, searchable.
                Select::make('parent_id')
                    ->label('Parent Account')
                    ->relationship('parent', 'name')
                    ->searchable()
                    ->preload()
                    ->nullable(),

                // Nova: Boolean is_active (Active).
                Toggle::make('is_active')
                    ->label('Active')
                    ->default(true),

                // Nova: Boolean allow_manual_entry — hideFromIndex (form only here).
                Toggle::make('allow_manual_entry')
                    ->label('Allow Manual Entry')
                    ->default(true),

                // Nova: Textarea description — nullable, hideFromIndex.
                Textarea::make('description')
                    ->nullable()
                    ->columnSpanFull(),

                // normal_balance and balance are Nova exceptOnForms (auto-derived) — omitted from the form.
            ]);
    }
}
