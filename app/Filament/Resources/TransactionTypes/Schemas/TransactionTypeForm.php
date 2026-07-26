<?php

namespace App\Filament\Resources\TransactionTypes\Schemas;

use App\Models\TransactionType;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class TransactionTypeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(100)
                    ->unique(table: TransactionType::class, column: 'name', ignoreRecord: true),

                TextInput::make('code')
                    ->required()
                    ->maxLength(50)
                    ->unique(table: TransactionType::class, column: 'code', ignoreRecord: true)
                    ->helperText('Stable slug, e.g. salary, rent, food'),

                Select::make('account_id')
                    ->label('Default Account')
                    ->relationship('account', 'name')
                    ->searchable()
                    ->preload()
                    ->nullable()
                    ->helperText('Expense/liability account debited when a payment of this type is approved'),

                Textarea::make('description')
                    ->nullable(),

                Toggle::make('is_active')
                    ->label('Active')
                    ->default(true),
            ]);
    }
}
