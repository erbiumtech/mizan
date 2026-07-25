<?php

namespace App\Filament\Resources\Contacts\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ContactForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),

                Select::make('kind')
                    ->label('Kind')
                    ->options([
                        'customer' => 'Customer',
                        'supplier' => 'Supplier',
                        'both' => 'Both',
                    ])
                    ->required(),

                TextInput::make('email')
                    ->label('Email')
                    ->email()
                    ->nullable(),

                TextInput::make('phone')
                    ->label('Phone')
                    ->nullable(),

                TextInput::make('address_line_1')
                    ->label('Address Line 1')
                    ->nullable(),

                TextInput::make('address_line_2')
                    ->label('Address Line 2')
                    ->nullable(),

                TextInput::make('ntn')
                    ->label('NTN')
                    ->nullable(),

                TextInput::make('cnic')
                    ->label('CNIC')
                    ->nullable(),

                Select::make('bank_id')
                    ->label('Bank')
                    ->relationship('bank', 'bank_name')
                    ->searchable()
                    ->preload()
                    ->nullable()
                    ->helperText('For paying suppliers through the bank payment flow'),

                Toggle::make('is_active')
                    ->label('Active'),

                ...\App\Filament\Support\CustomFieldsSchema::form(\App\Models\Contact::class),
            ]);
    }
}
