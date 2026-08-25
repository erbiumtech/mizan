<?php

namespace App\Modules\Inventory\Reporting;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockLocation;
use App\Modules\Inventory\Models\StockMovement;
use App\Support\Reporting\Dataset;
use App\Support\Reporting\DatasetColumn;
use App\Support\Reporting\DatasetFilter;

/**
 * Stock movements — `docs/reports-expansion-plan.md` Phase 6, item 1.
 *
 * **The movements, not the stock.** Stock on hand is a balance and Phase 2.4's report states it from
 * `InventoryValuationService`, which walks these rows applying a valuation method. A builder cannot do that —
 * FIFO is an ordered consumption of layers, not an aggregate — so what this subject offers is the ledger of
 * movements: what came in, what went out, when, at what cost. "What is it worth now" stays a coded report,
 * which is item 7's rule arriving from the other direction.
 *
 * **`type` is the column that makes this useful and the one to be careful with.** A purchase and a sale are
 * both rows here with positive quantities; the direction is in the type, exactly as `CashCommitmentReports`
 * keeps direction in a column rather than in a sign. So a report that sums quantity across types is summing
 * things that move opposite ways — which is why the type is a filter and a groupable column, and why summing
 * without one of those is the user's mistake to make rather than a shape this hides.
 *
 * **Permission is `ProductView`.** There is no stock-movement permission, and the register is what a product's
 * own screen shows: anybody who may see a product may see what has moved.
 */
class StockMovementDataset extends Dataset
{
    public static function label(): string
    {
        return 'Stock movements';
    }

    public static function description(): string
    {
        return 'One movement of stock: a receipt, an issue, a transfer or an adjustment, with its cost.';
    }

    public static function model(): string
    {
        return StockMovement::class;
    }

    public static function permission(): string
    {
        return 'ProductView';
    }

    public static function periodColumn(): ?string
    {
        return 'movement_date';
    }

    public static function columns(): array
    {
        return [
            DatasetColumn::make('movement_date', 'Date', DatasetColumn::DATE),
            DatasetColumn::make('type', 'Type'),
            DatasetColumn::related('product', 'Product', 'product.name', groupBy: 'product_id'),
            DatasetColumn::related('product_sku', 'SKU', 'product.sku', groupBy: 'product_id'),
            DatasetColumn::related('location', 'Location', 'stockLocation.name', groupBy: 'stock_location_id'),

            DatasetColumn::make('quantity', 'Quantity', DatasetColumn::NUMBER),
            DatasetColumn::make('unit_cost', 'Unit cost', DatasetColumn::MONEY),
            DatasetColumn::make('unit_price', 'Unit price', DatasetColumn::MONEY),
            DatasetColumn::make('total_cost', 'Total cost', DatasetColumn::MONEY),

            /*
             * What is left of this layer.
             *
             * Only receipts carry it — an issue consumes layers rather than creating one — so a report over
             * every type shows it blank for half its rows. Worth offering anyway: "which receipts are still
             * unconsumed" is the question behind a stock-ageing enquiry, and it is one filter away.
             */
            DatasetColumn::make('remaining_quantity', 'Remaining', DatasetColumn::NUMBER, groupable: false),

            DatasetColumn::make('reference', 'Reference', groupable: false),
        ];
    }

    public static function filters(): array
    {
        return [
            DatasetFilter::dateRange('period', 'Movement date', 'movement_date'),
            /*
             * The types this company's movements actually use.
             *
             * Read off the rows rather than restated from the model's constants, and that is deliberate:
             * `StockMovement` declares five types and `InventoryService` writes two more — `purchase` and
             * `sale` — as literals, so a list assembled from the constants would be missing the two
             * commonest ones. Reading the column cannot drift from what is stored, and a type this company
             * has never recorded is a filter that would match nothing.
             *
             * Labels come from the map below where there is one and from the value itself otherwise, so a
             * type added tomorrow appears reading tidily rather than not at all.
             */
            DatasetFilter::select('type', 'Type', 'type', function (): array {
                $labels = [
                    'purchase' => 'Purchase',
                    'sale' => 'Sale',
                    StockMovement::TYPE_ISSUE => 'Issue',
                    StockMovement::TYPE_RETURN => 'Return',
                    StockMovement::TYPE_TRANSFER => 'Transfer',
                    StockMovement::TYPE_WASTE => 'Waste',
                    StockMovement::TYPE_COUNT_ADJUSTMENT => 'Count adjustment',
                ];

                return static::query()
                    ->distinct()
                    ->orderBy('type')
                    ->pluck('type')
                    ->mapWithKeys(fn (string $type): array => [
                        $type => $labels[$type] ?? str($type)->headline()->toString(),
                    ])
                    ->all();
            }),
            DatasetFilter::select(
                'product',
                'Product',
                'product_id',
                fn (): array => Product::query()->orderBy('name')->pluck('name', 'id')->all(),
            ),
            DatasetFilter::select(
                'location',
                'Location',
                'stock_location_id',
                fn (): array => StockLocation::query()->orderBy('name')->pluck('name', 'id')->all(),
            ),
            DatasetFilter::search('reference', 'Reference contains', ['reference']),
        ];
    }
}
