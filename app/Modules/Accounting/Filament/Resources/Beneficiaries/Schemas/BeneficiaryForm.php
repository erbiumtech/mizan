<?php

namespace App\Modules\Accounting\Filament\Resources\Beneficiaries\Schemas;

use App\Filament\Support\CustomFieldsSchema;
use App\Modules\Accounting\Models\Beneficiary;
use App\Modules\Accounting\Models\WithholdingSection;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class BeneficiaryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Who is being paid')
                    ->description('The name the transfer is made out to, and the identity behind it.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->helperText('Non-employee payee: landlord, caterer, vendor…'),

                        Select::make('id_type')
                            ->label(__('ID Type'))
                            ->options([
                                'CNIC' => 'CNIC',
                                'NTN' => 'NTN',
                            ])
                            ->nullable(),

                        TextInput::make('id_number')
                            ->label(__('ID Number'))
                            ->nullable(),

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

                Section::make('Where the money goes')
                    ->description('The account a transfer lands in — what the bank file carries for this payee.')
                    ->columns(2)
                    ->schema([
                        Select::make('bank_id')
                            ->label(__('Bank'))
                            ->relationship('bank', 'bank_name')
                            ->searchable()
                            ->preload()
                            ->nullable(),

                        TextInput::make('account_no')
                            ->label(__('Account No'))
                            ->nullable(),

                        TextInput::make('iban')
                            ->label(__('IBAN'))
                            ->nullable()
                            ->maxLength(34),
                    ]),

                Section::make('How it is paid')
                    ->description('The payment instrument and the transaction type the bank expects on the file.')
                    ->columns(2)
                    ->schema([
                        Select::make('transaction_type_id')
                            ->label(__('Usual Transaction Type'))
                            ->relationship('transactionType', 'name')
                            ->searchable()
                            ->preload()
                            ->nullable()
                            ->helperText('What we usually pay this beneficiary for'),

                        Select::make('payment_type')
                            ->label(__('Default Payment Type'))
                            ->options([
                                'IBFT' => 'IBFT',
                                'BT' => 'BT',
                                'ACH' => 'ACH',
                                'RTGS' => 'RTGS',
                                'LBC' => 'LBC',
                            ])
                            ->default('IBFT')
                            ->required(),
                    ]),

                Section::make('Tax')
                    ->description('Whether tax is withheld from what they are paid, under which section, and at which rate.')
                    ->columns(2)
                    ->schema([
                        Toggle::make('is_contractor')
                            ->label(__('Contractor'))
                            // Withholding used to be impossible, and this sentence used to say so. It is now a
                            // question about the two fields below rather than about this toggle: a contractor with
                            // no section assigned is still paid gross, and a landlord with one is not.
                            ->helperText('A person paid for work rather than a landlord, utility or supplier. They appear on the Contractor Payments report. Whether tax is withheld is the section below.')
                            ->live(),

                        TextInput::make('engagement')
                            ->label(__('What they do'))
                            ->maxLength(255)
                            ->visible(fn (Get $get): bool => (bool) $get('is_contractor')),

                        /*
                     * Tax withheld at source — docs/erpnext-gap-plan.md Phase 4.
                     *
                     * The one place the deduction is turned on, and it is per supplier because that is what it
                     * is a fact about: which section applies depends on what this person is paid for. Empty for
                     * every beneficiary that exists today, and nothing is withheld while it is empty.
                     */

                        Select::make('withholding_section_id')
                            ->label(__('Withholding section'))
                            // Only sections this company has left switched on: assigning a lapsed one would withhold
                            // nothing, which is the one failure this feature exists to prevent.
                            ->relationship('withholdingSection', 'section', fn ($query) => $query->where('is_active', true))
                            ->getOptionLabelFromRecordUsing(fn (WithholdingSection $section): string => $section->describe())
                            ->searchable()
                            ->preload()
                            ->nullable()
                            ->live()
                            ->helperText('§153 of the Income Tax Ordinance. Leave empty to pay this beneficiary gross, '
                                .'which is how every supplier is paid until a section is chosen. The deduction is then '
                                .'made when a payment to them is approved, and appears on the Tax Withheld (§165) report.'),

                        /*
                     * Filer or not, which decides which of the section's two rates applies.
                     *
                     * Shown only once a section is chosen, because on its own it is a fact nobody acts on. Off
                     * by default, and the default is the safe one: the non-filer rate is the higher, and
                     * over-deducting is recoverable by the supplier through their own return while
                     * under-deducting is the company's liability plus a penalty.
                     */

                        Toggle::make('is_filer')
                            ->label(__('On the Active Taxpayers List'))
                            ->default(false)
                            ->visible(fn (Get $get): bool => filled($get('withholding_section_id')))
                            ->helperText('Check against FBR\'s list before turning this on: a filer is withheld from at '
                                .'roughly half the rate, and the difference is the company\'s liability if it is wrong.'),
                    ]),

                Section::make('Flags')
                    ->description('Whether this payee is still in use, and whether they hold the petty cash.')
                    ->columns(2)
                    ->schema([
                        Toggle::make('is_active')
                            ->label(__('Active'))
                            ->default(true),

                        // The person the month-end replenishment payment is made out to.
                        // The column, the model and both seeders have always had this
                        // flag; only the form was missing it, so a company that entered
                        // its beneficiaries by hand had no way to name a custodian and
                        // Petty Cash → Replenish Month could never succeed.
                        //
                        // One at a time: PettyCashService::replenish() takes the first
                        // active custodian it finds, so a second one would make which
                        // beneficiary gets paid depend on row order. Saving this on
                        // clears it everywhere else (see Beneficiary::booted()).

                        Toggle::make('is_petty_cash_custodian')
                            ->label(__('Petty cash custodian'))
                            ->helperText('Receives the month-end petty cash replenishment payment. Only one beneficiary can hold this at a time — turning it on here takes it off whoever holds it now.')
                            ->default(false),
                    ]),

                ...CustomFieldsSchema::form(Beneficiary::class),
            ]);
    }
}
