<?php

namespace App\Modules\Accounting\Filament\Resources\CompanyBankAccounts\Schemas;

use App\Modules\Accounting\Models\CompanyBankAccount;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

/**
 * One of the company's own bank accounts read in the list's view sheet — docs/side-sheets.md. The
 * account number and IBAN are the company's own, already on the form for whoever may view the record.
 */
class CompanyBankAccountInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextEntry::make('title')->label(__('Title')),
            TextEntry::make('bank.bank_name')->label(__('Bank'))->placeholder('—'),
            TextEntry::make('account_no')->label(__('Account No'))->copyable(),
            TextEntry::make('iban')->label(__('IBAN'))->copyable()->placeholder('—'),
            TextEntry::make('account.name')
                ->label(__('Ledger account'))
                ->formatStateUsing(fn ($state, CompanyBankAccount $record): string => trim(($record->account?->code ?? '').' '.$state))
                ->placeholder('—'),
            TextEntry::make('transactionType.name')->label(__('Purpose (Transaction Type)'))->placeholder('—'),
            IconEntry::make('is_default')->label(__('Default for its type'))->boolean(),
            IconEntry::make('is_active')->label(__('Active'))->boolean(),
        ]);
    }
}
