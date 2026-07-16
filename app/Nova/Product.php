<?php

namespace App\Nova;

use App\Nova\Fields\Currency;
use App\Services\InventoryValuationService;
use Laravel\Nova\Fields\Badge;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\HasMany;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;

class Product extends Resource
{
    public static $model = \App\Models\Product::class;

    public static $title = 'name';

    public static $search = ['sku', 'name'];

    public static $group = 'Inventory';

    public function fields(NovaRequest $request): array
    {
        $valuation = app(InventoryValuationService::class);

        return [
            ID::make()->sortable(),

            Text::make('SKU', 'sku')
                ->sortable()
                ->rules('required', 'max:50')
                ->creationRules('unique:products,sku')
                ->updateRules('unique:products,sku,{{resourceId}}'),

            Text::make('Name', 'name')->sortable()->rules('required', 'max:255'),

            Text::make('Description', 'description')->nullable()->hideFromIndex(),

            Text::make('Unit', 'unit')->rules('required', 'max:20')->hideFromIndex(),

            Badge::make('Valuation', 'valuation_method')->map([
                'fifo' => 'info',
                'lifo' => 'warning',
                'average' => 'success',
            ])->label(fn ($value) => strtoupper($value))->sortable(),

            Select::make('Valuation Method', 'valuation_method')
                ->options(['fifo' => 'FIFO', 'lifo' => 'LIFO', 'average' => 'Average Cost'])
                ->rules('required')
                ->onlyOnForms()
                ->help('How cost of goods sold is calculated'),

            Number::make('On Hand', fn () => $this->resource->exists ? $valuation->onHand($this->resource) : null)
                ->exceptOnForms(),

            Currency::make('Stock Value', fn () => $this->resource->exists ? $valuation->stockValue($this->resource) : null)
                ->currency('PKR')
                ->exceptOnForms(),

            Number::make('Reorder Level', 'reorder_level')->step(0.01)->hideFromIndex(),

            Badge::make('Stock Status', fn () => $this->resource->exists && $valuation->onHand($this->resource) <= (float) $this->resource->reorder_level ? 'low' : 'ok')
                ->map(['low' => 'danger', 'ok' => 'success'])
                ->exceptOnForms(),

            BelongsTo::make('Inventory Account', 'inventoryAccount', Account::class)->nullable()->hideFromIndex()->help('Defaults to 1300'),
            BelongsTo::make('COGS Account', 'cogsAccount', Account::class)->nullable()->hideFromIndex()->help('Defaults to 5050'),
            BelongsTo::make('Revenue Account', 'revenueAccount', Account::class)->nullable()->hideFromIndex()->help('Defaults to 4200'),

            Boolean::make('Active', 'is_active')->sortable(),

            HasMany::make('Movements', 'movements', StockMovement::class),
        ];
    }

    public function actions(NovaRequest $request): array
    {
        return [
            new Actions\ReceiveStock,
            new Actions\RecordSale,
            new Actions\AdjustStock,
        ];
    }
}
