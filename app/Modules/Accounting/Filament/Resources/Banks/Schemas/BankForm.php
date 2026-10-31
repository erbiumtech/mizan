<?php

namespace App\Modules\Accounting\Filament\Resources\Banks\Schemas;

use App\Modules\Core\Models\Bank;
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
                    ->label(__('Bank Code'))
                    ->required()
                    ->maxLength(20)
                    ->unique(table: Bank::class, column: 'bank_code', ignoreRecord: true)
                    ->helperText('IMD code used in IBFT bank files'),

                TextInput::make('bank_name')
                    ->label(__('Bank Name'))
                    ->required()
                    ->maxLength(255),

                TextInput::make('bank_short_code')
                    ->label(__('Bank Short Code'))
                    ->nullable()
                    ->maxLength(20)
                    ->helperText('Common abbreviation, e.g. HBL, MCB'),

                /*
             * docs/saas-launch-plan.md §14.10.
             *
             * The IMD code above is what the local IBFT file needs. SWIFT is what a
             * foreign remitter asks for, and the IBAN prefix is what lets an account
             * number typed against the wrong bank be spotted before a payment file.
             */
                TextInput::make('swift_code')
                    ->label(__('SWIFT / BIC'))
                    ->maxLength(16)
                    ->placeholder('SCBLPKKX')
                    ->helperText('Only needed for payments from outside Pakistan.'),

                TextInput::make('iban_prefix')
                    ->label(__('IBAN bank code'))
                    ->maxLength(8)
                    ->placeholder('SCBL')
                    ->helperText('The letters after the check digits in a PK IBAN — PK00 **SCBL** 0000…'),

                Toggle::make('is_active')
                    ->label(__('Active')),
            ]);
    }
}
