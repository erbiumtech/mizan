<?php

namespace App\Modules\Accounting\Filament\Resources\Beneficiaries\Schemas;

use App\Filament\Support\CustomFieldsSchema;
use App\Modules\Accounting\Models\Beneficiary;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class BeneficiaryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->helperText('Non-employee payee: landlord, caterer, vendor…'),

                Select::make('bank_id')
                    ->label('Bank')
                    ->relationship('bank', 'bank_name')
                    ->searchable()
                    ->preload()
                    ->nullable(),

                TextInput::make('account_no')
                    ->label('Account No')
                    ->nullable(),

                TextInput::make('iban')
                    ->label('IBAN')
                    ->nullable()
                    ->maxLength(34),

                Select::make('id_type')
                    ->label('ID Type')
                    ->options([
                        'CNIC' => 'CNIC',
                        'NTN' => 'NTN',
                    ])
                    ->nullable(),

                TextInput::make('id_number')
                    ->label('ID Number')
                    ->nullable(),

                TextInput::make('address_line_1')
                    ->label('Address Line 1')
                    ->nullable(),

                TextInput::make('address_line_2')
                    ->label('Address Line 2')
                    ->nullable(),

                TextInput::make('email')
                    ->label('Email')
                    ->email()
                    ->nullable(),

                TextInput::make('phone')
                    ->label('Phone')
                    ->nullable(),

                Select::make('transaction_type_id')
                    ->label('Usual Transaction Type')
                    ->relationship('transactionType', 'name')
                    ->searchable()
                    ->preload()
                    ->nullable()
                    ->helperText('What we usually pay this beneficiary for'),

                Select::make('payment_type')
                    ->label('Default Payment Type')
                    ->options([
                        'IBFT' => 'IBFT',
                        'BT' => 'BT',
                        'ACH' => 'ACH',
                        'RTGS' => 'RTGS',
                        'LBC' => 'LBC',
                    ])
                    ->default('IBFT')
                    ->required(),

                Toggle::make('is_active')
                    ->label('Active')
                    ->default(true),

                ...CustomFieldsSchema::form(Beneficiary::class),
            ]);
    }
}
