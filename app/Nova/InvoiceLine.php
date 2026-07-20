<?php

namespace App\Nova;

use App\Nova\Fields\Currency;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;

class InvoiceLine extends Resource
{
    public static $model = \App\Models\InvoiceLine::class;

    public static $title = 'description';

    public static $search = ['description'];

    public static $group = 'Invoicing';

    public static $displayInNavigation = false;

    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->sortable(),

            BelongsTo::make('Invoice', 'invoice', Invoice::class),

            BelongsTo::make('Product', 'product', Product::class)
                ->nullable()
                ->help('Leave empty for service / non-product lines'),

            Text::make('Description', 'description')->rules('required', 'max:255'),

            Number::make('Quantity', 'quantity')->step(0.01)->rules('required', 'numeric', 'min:0.01'),

            Currency::make('Unit Price', 'unit_price')->currency('PKR')->rules('required', 'numeric', 'min:0'),

            Currency::make('Line Total', 'line_total')->currency('PKR')->rules('required', 'numeric', 'min:0'),

            BelongsTo::make('Account Override', 'account', Account::class)
                ->nullable()
                ->hideFromIndex()
                ->help('Posting account for non-product lines'),
        ];
    }

    public static function authorizedToCreate(\Illuminate\Http\Request $request): bool
    {
        return $request->user()?->can('InvoiceUpdate') ?? false;
    }

    public function authorizedToUpdate(\Illuminate\Http\Request $request): bool
    {
        return $this->resource->invoice?->isDraft() && $request->user()?->can('InvoiceUpdate');
    }

    public function authorizedToDelete(\Illuminate\Http\Request $request): bool
    {
        return $this->authorizedToUpdate($request);
    }
}
