<?php

namespace App\Modules\Invoicing\Filament\Resources\Contacts\Schemas;

use App\Filament\Support\CustomFieldsSchema;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Models\TaxRate;
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

                Select::make('payment_terms_days')
                    ->label('Payment terms')
                    ->options(Contact::TERMS)
                    ->placeholder('None agreed')
                    ->selectablePlaceholder()
                    ->nullable()
                    ->helperText('Fills the due date on their invoices. "None agreed" leaves it blank — which is not the same as due on receipt, and keeps them out of the overdue buckets until somebody decides.'),

                // Which rate their invoice lines start on — the gap plan's §4 item 9, as a column rather
                // than a rule engine. The case that asks for it is an exporting company: foreign clients
                // zero-rated, local ones at the standard rate, and somebody changing the picker on every
                // line of every invoice until they forget once.
                Select::make('default_tax_rate_id')
                    ->label('Default tax rate')
                    ->options(fn (): array => TaxRate::active()
                        ->orderByDesc('is_default')
                        ->orderByDesc('rate')
                        ->get()
                        ->mapWithKeys(fn (TaxRate $rate): array => [$rate->id => $rate->label()])
                        ->all())
                    ->placeholder('The company default')
                    ->selectablePlaceholder()
                    ->nullable()
                    ->helperText('Fills the tax on new invoice lines for this party. It is a starting point — the rate on each line is what is charged, and can still be changed there.'),

                // The one control on the gap plan's list that prevents a loss rather than reporting one.
                // Checked when a sale is *issued*, against everything the customer already owes.
                TextInput::make('credit_limit')
                    ->label('Credit limit')
                    ->numeric()
                    ->minValue(0)
                    ->nullable()
                    ->helperText('In the base currency. A new invoice that would take what they owe past this is refused at issue — '
                        .'unless the person issuing may override credit limits. Leave blank for no limit.'),

                Select::make('bank_id')
                    ->label('Bank')
                    ->relationship('bank', 'bank_name')
                    ->searchable()
                    ->preload()
                    ->nullable()
                    ->helperText('For paying suppliers through the bank payment flow'),

                Toggle::make('is_active')
                    ->label('Active'),

                ...CustomFieldsSchema::form(Contact::class),
            ]);
    }
}
