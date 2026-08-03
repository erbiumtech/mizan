<?php

namespace App\Modules\Accounting\Filament\Resources\BankStatements\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class BankStatementForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('account_id')
                    ->label('Bank Account')
                    ->relationship(
                        'account',
                        'name',
                        fn ($query) => $query->postable()->ofType('asset'),
                    )
                    ->searchable()
                    ->preload()
                    ->required(),

                DatePicker::make('statement_date')
                    ->label('Statement Date')
                    ->required(),

                TextInput::make('opening_balance')
                    ->label('Opening Balance')
                    ->numeric()
                    ->default(0),

                TextInput::make('closing_balance')
                    ->label('Closing Balance')
                    ->numeric()
                    ->default(0),

                Select::make('status')
                    ->options([
                        'draft' => 'Draft',
                        'in_progress' => 'In Progress',
                        'completed' => 'Completed',
                    ])
                    ->default('draft')
                    ->required(),
            ]);
    }
}
