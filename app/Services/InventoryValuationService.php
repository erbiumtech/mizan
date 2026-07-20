<?php

namespace App\Services;

use App\Models\Product;
use App\Models\StockMovement;
use InvalidArgumentException;

/**
 * The costing engine: FIFO / LIFO consume purchase lots
 * (remaining_quantity); average cost uses the running weighted average of
 * stock on hand. Pure calculations — no journal posting here.
 */
class InventoryValuationService
{
    public function onHand(Product $product): float
    {
        return round((float) $product->movements()->sum('quantity'), 2);
    }

    /**
     * Value of stock on hand: everything that entered at cost, minus the
     * COGS taken out by sales/write-offs.
     */
    public function stockValue(Product $product): float
    {
        $in = (float) $product->movements()
            ->where('quantity', '>', 0)
            ->selectRaw('COALESCE(SUM(quantity * unit_cost), 0) as v')
            ->value('v');

        $out = (float) $product->movements()
            ->where('quantity', '<', 0)
            ->sum('total_cost');

        return round($in - $out, 2);
    }

    public function averageCost(Product $product): float
    {
        $onHand = $this->onHand($product);

        return $onHand > 0 ? round($this->stockValue($product) / $onHand, 4) : 0.0;
    }

    /**
     * The cost of selling/writing off $quantity units by the product's
     * valuation method. For FIFO/LIFO this CONSUMES purchase lots
     * (decrements remaining_quantity) — call inside a transaction.
     */
    public function costOfSale(Product $product, float $quantity): float
    {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('Quantity must be positive.');
        }

        if ($quantity > $this->onHand($product) + 0.001) {
            throw new InvalidArgumentException(
                "Insufficient stock for {$product->sku}: on hand ".$this->onHand($product).", requested {$quantity}."
            );
        }

        if ($product->valuation_method === Product::METHOD_AVERAGE) {
            return round($this->averageCost($product) * $quantity, 2);
        }

        return $this->consumeLots($product, $quantity);
    }

    protected function consumeLots(Product $product, float $quantity): float
    {
        $lots = $product->movements()
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
}
