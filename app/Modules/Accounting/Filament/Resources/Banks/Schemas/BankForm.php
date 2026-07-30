<?php

namespace App\Modules\Accounting\Filament\Resources\Banks\Schemas;

use App\Modules\Accounting\Models\Bank;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class BankForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('bank_code')
                    ->label('Bank Code')
                    ->required()
                    ->maxLength(20)
                    ->unique(table: Bank::class, column: 'bank_code', ignoreRecord: true)
                    ->helperText('IMD code used in IBFT bank files'),

                TextInput::make('bank_name')
                    ->label('Bank Name')
                    ->required()
                    ->maxLength(255),

                TextInput::make('bank_short_code')
                    ->label('Bank Short Code')
                    ->nullable()
                    ->maxLength(20)
                    ->helperText('Common abbreviation, e.g. HBL, MCB'),

                Toggle::make('is_active')
                    ->label('Active'),
            ]);
    }
}
