<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockLocation;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * The costing engine: FIFO / LIFO consume purchase lots
 * (remaining_quantity); average cost uses the running weighted average of
 * stock on hand. Pure calculations — no journal posting here.
 *
 * **Every read takes an optional location**, which is the one change to this API that
 * `docs/construction-management-plan.md` §6 and `docs/retail-stores-pos-plan.md` §2.1 both asked for. Before it,
 * `onHand()` summed every movement for a product everywhere and "what steel is on site" had no answer at all.
 *
 * **Null means everywhere, and that is deliberately the default.** Every existing caller passes nothing and gets exactly
 * what it got before — a company-wide figure, which is the right answer for a business with one store and the only
 * answer available for movements that predate locations. A caller that cares about one place says so.
 *
 * **The lot consumption is scoped too, and that is the part that would have been wrong if it were not.** FIFO lots are
 * rows of stock, and stock in a warehouse cannot be consumed by an issue on a building site forty miles away: unscoped,
 * the cheapest lot anywhere in the company would price every issue, and both locations' valuations would drift with
 * nothing disagreeing.
 */
class InventoryValuationService
{
    public function onHand(Product $product, StockLocation|int|string|null $at = null): float
    {
        return round((float) $this->movements($product, $at)->sum('quantity'), 2);
    }

    /**
     * Value of stock on hand: everything that entered at cost, minus the
     * COGS taken out by sales/write-offs.
     */
    public function stockValue(Product $product, StockLocation|int|string|null $at = null): float
    {
        $in = (float) $this->movements($product, $at)
            ->where('quantity', '>', 0)
            ->selectRaw('COALESCE(SUM(quantity * unit_cost), 0) as v')
            ->value('v');

        $out = (float) $this->movements($product, $at)
            ->where('quantity', '<', 0)
            ->sum('total_cost');

        return round($in - $out, 2);
    }

    public function averageCost(Product $product, StockLocation|int|string|null $at = null): float
    {
        $onHand = $this->onHand($product, $at);

        return $onHand > 0 ? round($this->stockValue($product, $at) / $onHand, 4) : 0.0;
    }

    /**
     * What is on hand at every location that holds any, keyed by location id.
     *
     * One grouped query rather than a call per location, because the caller that wants this is a report and a call per
     * row is the trap every plan in this repository names. A `null` key is stock that predates locations or was written
     * without one — shown rather than folded into a location, because "correct in total and wrong at every location" is
     * the failure §6 is about.
     *
     * @return array<int|string, float>
     */
    public function onHandByLocation(Product $product): array
    {
        return $product->movements()
            ->selectRaw('stock_location_id, COALESCE(SUM(quantity), 0) as qty')
            ->groupBy('stock_location_id')
            ->get()
            ->mapWithKeys(fn ($row): array => [$row->stock_location_id ?? '' => round((float) $row->qty, 2)])
            ->filter(fn (float $qty): bool => $qty != 0.0)
            ->all();
    }

    /**
     * The cost of selling/writing off $quantity units by the product's
     * valuation method. For FIFO/LIFO this CONSUMES purchase lots
     * (decrements remaining_quantity) — call inside a transaction.
     *
     * `$at` scopes both the sufficiency check and the lots consumed, so an issue from a site store cannot be priced out
     * of a warehouse lot it could never have physically taken.
     */
    public function costOfSale(Product $product, float $quantity, StockLocation|int|string|null $at = null): float
    {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('Quantity must be positive.');
        }

        $onHand = $this->onHand($product, $at);

        if ($quantity > $onHand + 0.001) {
            throw new InvalidArgumentException(
                "Insufficient stock for {$product->sku}: on hand ".$onHand.", requested {$quantity}."
                .($at === null ? '' : ' at that location.')
            );
        }

        if ($product->valuation_method === Product::METHOD_AVERAGE) {
            return round($this->averageCost($product, $at) * $quantity, 2);
        }

        return $this->consumeLots($product, $quantity, $at);
    }

    protected function consumeLots(Product $product, float $quantity, StockLocation|int|string|null $at = null): float
    {
        $lots = $this->movements($product, $at)
            ->where('quantity', '>', 0)
            ->where('remaining_quantity', '>', 0)
            ->orderBy('movement_date', $product->valuation_method === Product::METHOD_LIFO ? 'desc' : 'asc')
            ->orderBy('id', $product->valuation_method === Product::METHOD_LIFO ? 'desc' : 'asc')
            ->lockForUpdate()
            ->get();

        $remaining = $quantity;
        $cost = 0.0;

        foreach ($lots as $lot) {
            if ($remaining <= 0) {
                break;
            }

            $take = min((float) $lot->remaining_quantity, $remaining);
            $cost += $take * (float) $lot->unit_cost;
            $remaining -= $take;

            $lot->update(['remaining_quantity' => round((float) $lot->remaining_quantity - $take, 2)]);
        }

        if ($remaining > 0.001) {
            throw new InvalidArgumentException(
                "Lot consumption came up short for {$product->sku} ({$remaining} of {$quantity} uncovered)."
            );
        }

        return round($cost, 2);
    }

    /**
     * A product's movements, optionally at one location.
     *
     * One place builds the filter so every figure above is scoped the same way. Two of them narrowing differently is how
     * a valuation comes to disagree with the on-hand it was divided by.
     */
    private function movements(Product $product, StockLocation|int|string|null $at): Builder
    {
        return $product->movements()->getQuery()->when(
            $at !== null,
            fn (Builder $query) => $query->where(
                'stock_location_id',
                $at instanceof StockLocation ? $at->getKey() : $at,
            ),
        );
    }
}
