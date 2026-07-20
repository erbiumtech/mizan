<?php

namespace App\Nova\Metrics;

use App\Models\Product;
use App\Services\InventoryValuationService;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Metrics\Value;
use Laravel\Nova\Metrics\ValueResult;

class LowStockProducts extends Value
{
    public $name = 'Products At / Below Reorder Level';

    public function calculate(NovaRequest $request): ValueResult
    {
        $valuation = app(InventoryValuationService::class);

        $low = Product::where('is_active', true)
            ->get()
            ->filter(fn (Product $p) => $valuation->onHand($p) <= (float) $p->reorder_level)
            ->count();

        return $this->result($low)->allowZeroResult();
    }

    public function ranges(): array
    {
        return [];
    }

    public function uriKey(): string
    {
        return 'low-stock-products';
    }
}
