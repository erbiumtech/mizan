<?php

namespace App\Nova;

use App\Nova\Fields\Currency;
use Laravel\Nova\Fields\Badge;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Date;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;

class StockMovement extends Resource
{
    public static $model = \App\Models\StockMovement::class;

    public static $title = 'id';

    public static $search = ['reference'];

    public static $group = 'Inventory';

    // Movements are created through the InventoryService only.
    public static function authorizedToCreate(\Illuminate\Http\Request $request): bool
    {
        return false;
    }

    public function authorizedToUpdate(\Illuminate\Http\Request $request): bool
    {
        return false;
    }

    public function authorizedToDelete(\Illuminate\Http\Request $request): bool
    {
        return false;
    }

    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->sortable(),

            BelongsTo::make('Product', 'product', Product::class)->sortable(),

            Badge::make('Type', 'type')->map([
                'purchase' => 'success',
                'sale' => 'info',
                'adjustment' => 'warning',
            ])->sortable(),

            Number::make('Quantity', 'quantity')->sortable(),

            Currency::make('Unit Cost', 'unit_cost')->currency('PKR'),

            Currency::make('Unit Price', 'unit_price')->currency('PKR'),

            Currency::make('COGS', 'total_cost')->currency('PKR'),

            Number::make('Lot Remaining', 'remaining_quantity')->hideFromIndex(),

            Date::make('Date', 'movement_date')->sortable(),

            Text::make('Reference', 'reference')->hideFromIndex(),

            BelongsTo::make('Journal Entry', 'journalEntry', JournalEntry::class)->nullable(),
        ];
    }
}
