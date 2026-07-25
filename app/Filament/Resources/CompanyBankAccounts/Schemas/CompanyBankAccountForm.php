<?php

namespace App\Filament\Resources\CompanyBankAccounts\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class CompanyBankAccountForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('title')
                    ->label('Title')
                    ->required()
                    ->maxLength(255),

                Select::make('bank_id')
                    ->label('Bank')
                    ->relationship('bank', 'bank_name')
                    ->searchable()
                    ->preload()
                    ->nullable(),

                TextInput::make('account_no')
                    ->label('Account No')
                    ->required()
                    ->maxLength(50),

                TextInput::make('iban')
                    ->label('IBAN')
                    ->nullable()
                    ->maxLength(34),

                Select::make('transaction_type_id')
                    ->label('Purpose (Transaction Type)')
                    ->relationship('transactionType', 'name')
                    ->searchable()
                    ->preload()
                    ->nullable()
                    ->helperText('What this account is earmarked for: Salary, Rent, Food…'),

                Toggle::make('is_default')
                    ->label('Default for its type')
                    ->helperText('Only one default per transaction type; saving unsets the others'),

                Toggle::make('is_active')
                    ->label('Active'),
            ]);
    }
}
