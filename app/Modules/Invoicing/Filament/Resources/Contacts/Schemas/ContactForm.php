<?php

namespace App\Modules\Invoicing\Filament\Resources\Contacts\Schemas;

use App\Filament\Support\CustomFieldsSchema;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Models\TaxRate;
use App\Support\EmployeeOptions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ContactForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Who they are')
                    ->description('The name on the invoice, whether they are a customer or a supplier, and who here looks after them.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),

                        Select::make('kind')
                            ->label(__('Kind'))
                            ->options([
                                'customer' => 'Customer',
                                'supplier' => 'Supplier',
                                'both' => 'Both',
                            ])
                            ->required(),

                        // The account columns — docs/bexio-gap-plan.md Phase 2, item 5.

                        TextInput::make('category')
                            ->label(__('Category'))
                            ->maxLength(100)
                            ->datalist(fn (): array => Contact::categories())
                            ->nullable()
                            ->helperText('Industry, region, tier — whatever you group customers by. What has been typed before is offered as you type.'),

                        // A plain column with the shared picker: Invoicing may not name Employees, so there is
                        // no relation to hang a relationship() select on. Same shape as construction_workers.

                        Select::make('owner_employee_id')
                            ->label(__('Account manager'))
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => EmployeeOptions::search($search))
                            ->getOptionLabelUsing(fn ($value): ?string => EmployeeOptions::labelFor($value))
                            ->nullable()
                            ->visible(fn (): bool => modules()->enabled('employees'))
                            ->helperText('Who looks after this account.'),

                        Toggle::make('is_active')
                            ->label(__('Active')),
                    ]),

                Section::make('How to reach them')
                    ->description('Where invoices, statements and reminders are sent.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('email')
                            ->label(__('Email'))
                            ->email()
                            ->nullable(),

                        TextInput::make('phone')
                            ->label(__('Phone'))
                            ->nullable(),

                        TextInput::make('address_line_1')
                            ->label(__('Address Line 1'))
                            ->nullable(),

                        TextInput::make('address_line_2')
                            ->label(__('Address Line 2'))
                            ->nullable(),
                    ]),

                Section::make('Tax identity')
                    ->description('What the FBR knows them by.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('ntn')
                            ->label(__('NTN'))
                            ->nullable(),

                        TextInput::make('cnic')
                            ->label(__('CNIC'))
                            ->nullable(),
                    ]),

                Section::make('Trading terms')
                    ->description('What this contact is sold at and how long they get to pay — the defaults a new invoice starts from.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('default_discount_pct')
                            ->label(__('Standing discount %'))
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->default(0)
                            ->helperText('Taken off a product\'s list price when it lands on their quote or invoice line. The line carries the resulting price.'),

                        Select::make('payment_terms_days')
                            ->label(__('Payment terms'))
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
                            ->label(__('Default tax rate'))
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
                            ->label(__('Credit limit'))
                            ->numeric()
                            ->minValue(0)
                            ->nullable()
                            ->helperText('In the base currency. A new invoice that would take what they owe past this is refused at issue — '
                                .'unless the person issuing may override credit limits. Leave blank for no limit.'),
                    ]),

                Section::make('Their bank')
                    ->description('Where money is sent to them. Needed for a supplier paid by transfer.')
                    ->columns(2)
                    ->schema([
                        Select::make('bank_id')
                            ->label(__('Bank'))
                            ->relationship('bank', 'bank_name')
                            ->searchable()
                            ->preload()
                            ->nullable()
                            ->helperText('For paying suppliers through the bank payment flow'),

                        // The other half of paying a supplier — docs/bexio-gap-plan.md Phase 3. An IBAN is what
                        // goes to another bank; the account number is needed when they bank with us.

                        TextInput::make('iban')
                            ->label(__('IBAN'))
                            ->maxLength(34)
                            ->nullable()
                            ->helperText('For "Pay through the bank" on their bills.'),

                        TextInput::make('account_no')
                            ->label(__('Account number'))
                            ->maxLength(50)
                            ->nullable()
                            ->helperText('Needed instead of the IBAN when they bank with the same bank the payment leaves from.'),
                    ]),

                ...CustomFieldsSchema::form(Contact::class),
            ]);
    }
}
