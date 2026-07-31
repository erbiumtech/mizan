<?php

namespace App\Filament\Resources\Payments\Schemas;

use App\Models\Beneficiary;
use App\Models\Employee;
use App\Models\Payment;
use App\Support\EmployeeOptions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\MorphToSelect;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class PaymentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                // MorphTo `payable` — types [Employee, Beneficiary]; searchable
                MorphToSelect::make('payable')
                    ->types([
                        MorphToSelect\Type::make(Employee::class)
                            ->titleAttribute('employee_id')
                            // Searching by name needs the landlord users table,
                            // which no single query may join to employees — so
                            // match the resolved ids instead of a title column.
                            ->modifyOptionsQueryUsing(fn ($query) => $query->with('user'))
                            ->getSearchResultsUsing(fn (string $search): array => EmployeeOptions::search($search))
                            ->getOptionLabelFromRecordUsing(fn ($record) => $record->display_label),
                        MorphToSelect\Type::make(Beneficiary::class)
                            ->titleAttribute('name'),
                    ])
                    ->searchable()
                    ->label('Payable — Employee (salary) or Beneficiary (rent, food…)'),

                Select::make('transaction_type_id')
                    ->label('Transaction Type')
                    ->relationship('transactionType', 'name')
                    ->searchable()
                    ->preload(),

                Select::make('company_bank_account_id')
                    ->label('Debit Account')
                    ->relationship('companyBankAccount', 'title')
                    ->searchable()
                    ->preload()
                    ->nullable()
                    ->helperText('Company account the payment debits; falls back to IPAYMENTS_DEBIT_ACCOUNT'),

                TextInput::make('amount')
                    ->numeric()
                    ->step(0.01)
                    ->required()
                    ->minValue(0.01)
                    ->prefix('PKR'),

                TextInput::make('details')
                    ->label('Details')
                    ->required()
                    ->maxLength(140)
                    ->helperText('Payment Details in the bank file, e.g. "Office Rent July 2026"'),

                TextInput::make('reference')
                    ->nullable()
                    ->maxLength(255),

                DatePicker::make('value_date')
                    ->label('Value Date')
                    ->nullable(),

                Select::make('payment_type')
                    ->label('Payment Type')
                    ->options(array_combine(['IBFT', 'BT', 'ACH', 'RTGS', 'LBC'], ['IBFT', 'BT', 'ACH', 'RTGS', 'LBC']))
                    ->nullable()
                    // readonly when the record exists and is no longer a draft (parity with Nova readonly closure)
                    ->disabled(fn (?Payment $record): bool => $record?->exists && $record->status !== Payment::STATUS_DRAFT)
                    ->helperText('Leave empty to auto-resolve: RTGS ≥ 1,000,000, BT for same-bank, else IBFT / beneficiary default'),
            ]);
    }
}
