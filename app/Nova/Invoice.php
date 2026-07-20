<?php

namespace App\Nova;

use App\Nova\Fields\Currency;
use Laravel\Nova\Fields\Badge;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Date;
use Laravel\Nova\Fields\HasMany;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\URL;
use Laravel\Nova\Http\Requests\NovaRequest;

class Invoice extends Resource
{
    public static $model = \App\Models\Invoice::class;

    public static $title = 'invoice_number';

    public static $search = ['invoice_number'];

    public static $group = 'Invoicing';

    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->sortable(),

            Text::make('Number', 'invoice_number')->sortable()->exceptOnForms(),

            Badge::make('Kind', 'kind')->map([
                'sale' => 'info',
                'purchase' => 'warning',
            ])->sortable(),

            Select::make('Kind', 'kind')
                ->options(['sale' => 'Sale (customer invoice)', 'purchase' => 'Purchase (supplier bill)'])
                ->rules('required')
                ->onlyOnForms()
                ->hideWhenUpdating(),

            BelongsTo::make('Contact', 'contact', Contact::class)->sortable(),

            Date::make('Invoice Date', 'invoice_date')->sortable()->rules('required'),
            Date::make('Due Date', 'due_date')->nullable()->hideFromIndex(),

            Badge::make('Status', 'status')->map([
                'draft' => 'warning',
                'issued' => 'info',
                'partially_paid' => 'info',
                'paid' => 'success',
                'void' => 'danger',
            ])->sortable(),

            Currency::make('Subtotal', 'subtotal')->currency('PKR')->rules('required', 'numeric', 'min:0')->hideFromIndex(),
            Currency::make('Tax', 'tax_amount')->currency('PKR')->rules('required', 'numeric', 'min:0')->hideFromIndex(),
            Currency::make('Total', 'total')->currency('PKR')->rules('required', 'numeric', 'min:0')->sortable(),
            Currency::make('Paid', 'amount_paid')->currency('PKR')->exceptOnForms()->sortable(),
            Currency::make('Outstanding', fn () => $this->resource->exists ? $this->resource->outstanding() : null)
                ->currency('PKR')
                ->exceptOnForms(),

            Text::make('Memo', 'memo')->nullable()->hideFromIndex(),

            BelongsTo::make('Journal Entry', 'journalEntry', JournalEntry::class)
                ->nullable()
                ->exceptOnForms(),

            URL::make('PDF', fn () => $this->resource->exists ? url("/reports/invoice/{$this->resource->id}/pdf") : null)
                ->displayUsing(fn () => 'Download PDF')
                ->onlyOnDetail(),

            HasMany::make('Lines', 'lines', InvoiceLine::class),
        ];
    }

    public function actions(NovaRequest $request): array
    {
        return [
            new Actions\IssueInvoice,
            new Actions\RecordInvoicePayment,
            new Actions\VoidInvoice,
        ];
    }
}
