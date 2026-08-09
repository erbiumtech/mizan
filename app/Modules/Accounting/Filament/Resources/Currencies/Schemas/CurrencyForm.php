<?php

namespace App\Modules\Accounting\Filament\Resources\Currencies\Schemas;

use App\Modules\Accounting\Models\Currency;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class CurrencyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')
                ->label('Code')
                ->required()
                ->maxLength(3)
                ->minLength(3)
                ->alpha()
                ->unique(ignoreRecord: true)
                ->disabled(fn (?Currency $record): bool => (bool) $record?->rates()->exists())
                ->helperText('ISO 4217 — PKR, EUR, USD. Fixed once a rate has been recorded against it.'),

            TextInput::make('name')->required()->maxLength(255),

            TextInput::make('symbol')
                ->maxLength(8)
                ->helperText('Shown wherever an amount in this currency is printed.'),

            TextInput::make('decimals')
                ->numeric()
                ->minValue(0)
                ->maxValue(4)
                ->default(2),

            Toggle::make('is_active')
                ->label('In use')
                ->default(true)
                ->helperText('Switch off to stop offering it, without touching anything already posted in it.'),
        ]);
    }
}
