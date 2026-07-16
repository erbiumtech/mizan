<?php

namespace App\Nova;

use Laravel\Nova\Fields\Badge;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Currency;
use Laravel\Nova\Fields\Date;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\MorphTo;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;

class Payment extends Resource
{
    public static $model = \App\Models\Payment::class;

    public static $title = 'details';

    public static $search = ['details', 'reference'];

    public static $group = 'Accounting';

    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->sortable(),

            MorphTo::make('Payable', 'payable')
                ->types([Employee::class, Beneficiary::class])
                ->searchable()
                ->help('Employee (salary) or Beneficiary (rent, food…)'),

            BelongsTo::make('Transaction Type', 'transactionType', TransactionType::class),

            BelongsTo::make('Debit Account', 'companyBankAccount', CompanyBankAccount::class)
                ->nullable()
                ->help('Company account the payment debits; falls back to IPAYMENTS_DEBIT_ACCOUNT'),

            Currency::make('Amount', 'amount')->currency('PKR')->sortable()->rules('required', 'numeric', 'min:0.01'),

            Text::make('Details', 'details')->rules('required', 'max:140')
                ->help('Payment Details in the bank file, e.g. "Office Rent July 2026"'),

            Text::make('Reference', 'reference')->nullable()->hideFromIndex(),

            Date::make('Value Date', 'value_date')->nullable(),

            Select::make('Payment Type', 'payment_type')
                ->options(array_combine(['IBFT', 'BT', 'ACH', 'RTGS', 'LBC'], ['IBFT', 'BT', 'ACH', 'RTGS', 'LBC']))
                ->nullable()
                ->displayUsing(fn () => $this->resource->exists ? $this->resource->resolvedPaymentType() : null)
                ->readonly(fn () => $this->resource->exists && $this->resource->status !== \App\Models\Payment::STATUS_DRAFT)
                ->help('Leave empty to auto-resolve: RTGS ≥ 1,000,000, BT for same-bank, else IBFT / beneficiary default'),

            Badge::make('Status', 'status')->map([
                'draft' => 'warning',
                'approved' => 'info',
                'exported' => 'success',
                'paid' => 'success',
            ])->sortable(),

            BelongsTo::make('Journal Entry', 'journalEntry', JournalEntry::class)
                ->nullable()
                ->exceptOnForms(),
        ];
    }

    public function actions(NovaRequest $request): array
    {
        return [
            new Actions\ApprovePayment,
        ];
    }
}
