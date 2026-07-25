<?php

namespace App\Filament\Resources\BankStatementLines\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class BankStatementLineForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                // Nova: BelongsTo bankStatement & matchedLine are exceptOnForms; match_status has no form field.

                DatePicker::make('transaction_date')
                    ->label('Transaction Date')
                    ->required(),

                TextInput::make('description')
                    ->nullable(),

                TextInput::make('reference')
                    ->nullable(),

                TextInput::make('amount')
                    ->numeric()
                    ->required()
                    ->helperText('Signed: positive = money in, negative = money out'),
            ]);
    }
}
