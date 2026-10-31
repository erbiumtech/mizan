<?php

namespace App\Modules\Accounting\Filament\Resources\CompanyBankAccounts\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class CompanyBankAccountForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('title')
                    ->label(__('Title'))
                    ->required()
                    ->maxLength(255),

                Select::make('bank_id')
                    ->label(__('Bank'))
                    ->relationship('bank', 'bank_name')
                    ->searchable()
                    ->preload()
                    ->nullable(),

                TextInput::make('account_no')
                    ->label(__('Account No'))
                    ->required()
                    ->maxLength(50),

                TextInput::make('iban')
                    ->label(__('IBAN'))
                    ->nullable()
                    ->maxLength(34),

                Select::make('account_id')
                    ->label(__('Ledger account'))
                    ->relationship('account', 'name', fn (Builder $query) => $query->postable()->ofType('asset'))
                    ->getOptionLabelFromRecordUsing(fn ($record): string => $record->code.' '.$record->name)
                    ->searchable()
                    ->preload()
                    ->required()
                    ->helperText('Where the money in this account is booked: payments drawn on it credit this ledger account, and its bank statements are reconciled against it. Give each bank its own account to keep their balances apart.'),

                Select::make('transaction_type_id')
                    ->label(__('Purpose (Transaction Type)'))
                    ->relationship('transactionType', 'name')
                    ->searchable()
                    ->preload()
                    ->nullable()
                    ->helperText('What this account is earmarked for: Salary, Rent, Food…'),

                Toggle::make('is_default')
                    ->label(__('Default for its type'))
                    ->helperText('Only one default per transaction type; saving unsets the others'),

                Toggle::make('is_active')
                    ->label(__('Active')),
            ]);
    }
}
