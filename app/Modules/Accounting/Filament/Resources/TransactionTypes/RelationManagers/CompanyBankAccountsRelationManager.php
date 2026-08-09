<?php

namespace App\Modules\Accounting\Filament\Resources\TransactionTypes\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CompanyBankAccountsRelationManager extends RelationManager
{
    protected static string $relationship = 'companyBankAccounts';

    protected static ?string $title = 'Company Bank Accounts';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns([
                TextColumn::make('title')
                    ->searchable(),

                TextColumn::make('account_no')
                    ->label('Account No')
                    ->searchable(),

                TextColumn::make('iban')
                    ->label('IBAN')
                    ->searchable(),

                IconColumn::make('is_default')
                    ->label('Default')
                    ->boolean(),

                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
            ]);
    }
}
