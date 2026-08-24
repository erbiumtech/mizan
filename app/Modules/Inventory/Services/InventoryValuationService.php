<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockLocation;
use App\Modules\Inventory\Models\StockMovement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
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
     * On hand, value and average cost for every product at once — `docs/reports-expansion-plan.md` Phase 2.4.
     *
     * **One grouped query for the whole catalogue**, where the three methods above are per product and
     * `stockValue()` alone is two queries. Called down a product list that is 3n+ queries for one screen, and
     * this plan's risk list names that shape by name: "these reports are loops over employees, products and
     * projects, and this is exactly how `/payroll-runs` came to run ~100 queries a page."
     *
     * **It must agree with the per-product methods exactly, and `InventoryStockReportTest` asserts that
     * product by product rather than taking it on trust.** That is the same protection the general ledger's
     * batching got in Phase 1.1: two ways of computing one figure is a drift waiting to happen, and the
     * equivalence test is the specification rather than a nicety. The arithmetic below is deliberately the
     * same three expressions — `sum(quantity)`, `sum(quantity * unit_cost)` over the ins less
     * `sum(total_cost)` over the outs, and one divided by the other — so a reader can check it against the
     * methods above by eye.
     *
     * As at a date, because a valuation is a balance and a balance is always as at something. Null is
     * everything, matching the rest of this class.
     *
     * @return array<int, array{on_hand: float, value: float, average_cost: float, last_movement: ?string}>
     *                                                                                                      product id => figures
     */
    public function valuationForAll(?string $asOf = null): array
    {
        $rows = StockMovement::query()
            ->when($asOf, fn (Builder $query) => $query->whereDate('movement_date', '<=', $asOf))
            ->groupBy('product_id')
            ->selectRaw('product_id')
            ->selectRaw('COALESCE(SUM(quantity), 0) as on_hand')
            ->selectRaw('COALESCE(SUM(CASE WHEN quantity > 0 THEN quantity * unit_cost ELSE 0 END), 0) as cost_in')
            ->selectRaw('COALESCE(SUM(CASE WHEN quantity < 0 THEN total_cost ELSE 0 END), 0) as cost_out')
            // The most recent movement, for the "nothing has moved" flag. Free here, and a second query per
            // product everywhere else.
            ->selectRaw('MAX(movement_date) as last_movement')
            ->get();

        $valuation = [];

        foreach ($rows as $row) {
            $onHand = round((float) $row->on_hand, 2);
            $value = round((float) $row->cost_in - (float) $row->cost_out, 2);

            $valuation[(int) $row->product_id] = [
                'on_hand' => $onHand,
                'value' => $value,
                // Guarded exactly as `averageCost()` guards it: nought on hand has no average, and dividing
                // would be a crash on the one product a stocktake had just cleared out.
                'average_cost' => $onHand > 0 ? round($value / $onHand, 4) : 0.0,
                'last_movement' => $row->last_movement === null
                    ? null
                    : Carbon::parse($row->last_movement)->toDateString(),
            ];
        }

        return $valuation;
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

    /**
     * Consume stock and report **which lots it came from**, at what quantity and cost.
     *
     * `costOfSale()` returns one number, which is all a sale needs. A construction material issue needs more: §6 puts
     * a FIFO unit cost on the issue line, and the cost has to be traced back to the lot — because the lot knows the
     * goods receipt it arrived on, and that receipt knows the cost code the material was booked to. Without that, an
     * issue cannot reclassify cost from the code it was received at to the code it was used on.
     *
     * The FIFO ordering is the same code as `costOfSale()`, and deliberately so: two implementations of lot
     * consumption is two answers to "what did this cost", and the second one is always the one nobody tested.
     *
     * Average-cost products get one synthetic row with a null lot — there are no layers to name, and pretending
     * otherwise would invent a provenance the method cannot support.
     *
     * @return array<int, array{lot: ?\App\Modules\Inventory\Models\StockMovement, quantity: float, cost: float}>
     */
    public function consume(Product $product, float $quantity, StockLocation|int|string|null $at = null): array
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
            return [[
                'lot' => null,
                'quantity' => round($quantity, 2),
                'cost' => round($this->averageCost($product, $at) * $quantity, 2),
            ]];
        }

        return $this->takeLots($product, $quantity, $at);
    }

    protected function consumeLots(Product $product, float $quantity, StockLocation|int|string|null $at = null): float
    {
        return round(array_sum(array_column($this->takeLots($product, $quantity, $at), 'cost')), 2);
    }

    /**
     * The FIFO / LIFO walk itself, in one place.
     *
     * @return array<int, array{lot: \App\Modules\Inventory\Models\StockMovement, quantity: float, cost: float}>
     */
    protected function takeLots(Product $product, float $quantity, StockLocation|int|string|null $at = null): array
    {
        $lots = $this->movements($product, $at)
            ->where('quantity', '>', 0)
            ->where('remaining_quantity', '>', 0)
            ->orderBy('movement_date', $product->valuation_method === Product::METHOD_LIFO ? 'desc' : 'asc')
            ->orderBy('id', $product->valuation_method === Product::METHOD_LIFO ? 'desc' : 'asc')
            ->lockForUpdate()
            ->get();

        $remaining = $quantity;
        $taken = [];

        foreach ($lots as $lot) {
            if ($remaining <= 0) {
                break;
            }

            $take = min((float) $lot->remaining_quantity, $remaining);
            $remaining -= $take;

            $taken[] = [
                'lot' => $lot,
                'quantity' => round($take, 2),
                'cost' => round($take * (float) $lot->unit_cost, 2),
            ];

            $lot->update(['remaining_quantity' => round((float) $lot->remaining_quantity - $take, 2)]);
        }

        if ($remaining > 0.001) {
            throw new InvalidArgumentException(
                "Lot consumption came up short for {$product->sku} ({$remaining} of {$quantity} uncovered)."
            );
        }

        return $taken;
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
