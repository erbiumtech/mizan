<?php

namespace App\Modules\Inventory\Support;

use App\Modules\Accounting\Services\GeneralLedgerService;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Services\InventoryValuationService;
use App\Support\Reporting\ReportShapes;
use Illuminate\Support\Carbon;

/**
 * What is on the shelf and what it is worth — `docs/reports-expansion-plan.md` Phase 2.4.
 *
 * `InventoryValuationService::onHand()`, `stockValue()` and `averageCost()` were used by `InventoryService`
 * and `InvoiceService` when *posting*, and by nothing that answered "what is on the shelf and what is it
 * worth". The plan's phrase for that is the same as for the rest of Phase 1 and 2: the computation existed
 * and had no reader.
 *
 * **It reconciles, and unlike leave liability and unbilled WIP it has something to reconcile against.** Every
 * product's stock is held in an inventory account — its own if it names one, the default if it does not —
 * so the valuation of the products pointing at an account is the figure that account should hold. Phase 2's
 * rule applies in full here: the record row states both and the note says whether they agree.
 *
 * The account is resolved through `InventoryService::inventoryAccountId()`, the same method the posting uses.
 * This report first read `inventory_account_id` off the product and treated a null as stock in no account,
 * which its own tests disproved: the fallback means stock is never in no account, and two copies of that
 * rule would have had the report reconciling against one account while the ledger held another.
 *
 * **The flags are columns on the same rows, not separate reports**, which is the plan's own instruction:
 * "below-`reorder_level` and no-movement-in-N-days as flags on the same rows rather than as separate
 * reports". A product that is both below its reorder level *and* has not moved in six months is the
 * interesting case, and two reports would put those two facts on different screens.
 */
class InventoryReports
{
    use ReportShapes;

    /**
     * How long without a movement counts as stale.
     *
     * Ninety days rather than a setting, for now: it is a reading aid on a column and not a rule anything
     * acts on, so a company that disagrees is not blocked by it. It becomes a setting the day something
     * *does* act on it.
     */
    private const STALE_DAYS = 90;

    public function stockOnHand(string $asOf): array
    {
        $date = Carbon::parse($asOf);
        $valuation = app(InventoryValuationService::class)->valuationForAll($asOf);
        $inventory = app(InventoryService::class);

        // Every product, not only the ones with movements: a product with nothing on hand and a reorder
        // level is exactly what a reorder flag is for, and it has no row in `stock_movements` at all.
        $products = Product::query()
            ->where('is_active', true)
            ->with('inventoryAccount')
            ->orderBy('name')
            ->get();

        $rows = [];
        $value = 0.0;
        $belowReorder = 0;
        $stale = 0;
        $perAccount = [];

        foreach ($products as $product) {
            $figures = $valuation[$product->getKey()] ?? [
                'on_hand' => 0.0,
                'value' => 0.0,
                'average_cost' => 0.0,
                'last_movement' => null,
            ];

            /*
             * A reorder level of nought means there is no level, not a level of nought.
             *
             * The column defaults to `0`, so `null` never actually occurs — and treating nought as a real
             * threshold flagged every product that had been sold out, since `0 <= 0`. That is the opposite
             * of useful: a product with no reorder level is one nobody wants to be told about.
             */
            $reorder = (float) $product->reorder_level > 0 ? (float) $product->reorder_level : null;
            $isBelow = $reorder !== null && $figures['on_hand'] <= $reorder;
            $isStale = $this->isStale($figures['last_movement'], $date);

            $rows[] = [
                (string) $product->sku.' · '.$product->name,
                number_format($figures['on_hand'], 2),
                // A product with nothing on hand has no average cost — the service returns nought rather
                // than dividing, and a nought printed as money would claim the stock is free.
                $figures['on_hand'] > 0 ? number_format($figures['average_cost'], 2) : '—',
                number_format($figures['value'], 0),
                $reorder === null ? '—' : number_format($reorder, 2),
                // Both flags in one cell, because both at once is the case worth acting on and two columns
                // of ticks would need reading across.
                $this->flags($isBelow, $isStale, $figures['last_movement']),
            ];

            $value += $figures['value'];
            $belowReorder += $isBelow ? 1 : 0;
            $stale += $isStale ? 1 : 0;

            // Resolved the way the posting resolves it, not read off the product. A product naming no
            // account is held in the default one, so there is no such thing as stock in no account.
            $accountId = $inventory->inventoryAccountId($product);
            $perAccount[$accountId] = ($perAccount[$accountId] ?? 0.0) + $figures['value'];
        }

        $ledger = $this->ledgerValue(array_keys($perAccount), $asOf);
        $inactive = $this->inactiveStockValue($valuation, $inventory, array_keys($perAccount));

        return $this->table(
            'StockOnHand',
            'Stock on Hand',
            $this->subtitle('as at '.$asOf),
            ['Product', 'On hand', 'Average cost', 'Value', 'Reorder at', 'Flags'],
            'minmax(0, 1fr) 8rem 10rem 10rem 8rem 14rem',
            [1, 2, 3, 4],
            $rows,
            [
                ['label' => 'STOCK VALUE', 'value' => round($value, 2), 'accent' => true],
                ['label' => 'INVENTORY ACCOUNTS', 'value' => $ledger, 'accent' => false],
            ],
            $this->stockNote($rows, round($value, 2), $ledger, $belowReorder, $stale, $inactive),
            $rows === [] ? null : [
                'Total — '.count($rows).' products',
                '',
                '',
                number_format($value, 0),
                '',
                '',
            ],
            'No active product.',
        );
    }

    /**
     * Whether the valuation agrees with the accounts, and what to look at when it does not.
     *
     * **Stock held by deactivated products comes first among the explanations**, because it is the one that
     * makes a difference *expected*. The rows are active products; the accounts hold whatever was ever booked
     * to them, deactivated products included. So a company that switches a product off while it still has
     * stock has a real difference that is nobody's mistake, and calling it a discrepancy would be crying wolf.
     *
     * There is deliberately no "unmapped product" case. A product naming no inventory account is held in the
     * default one — `InventoryService::inventoryAccountId()` resolves it that way when posting — so stock is
     * never in no account at all. This report assumed otherwise until its own tests said so.
     *
     * @param  array<int, array<int, string>>  $rows
     */
    private function stockNote(array $rows, float $value, float $ledger, int $belowReorder, int $stale, float $inactive): string
    {
        if ($rows === []) {
            return 'NO ACTIVE PRODUCT';
        }

        $flags = array_filter([
            $belowReorder > 0 ? $belowReorder.' at or below reorder level' : null,
            $stale > 0 ? $stale.' with no movement in '.self::STALE_DAYS.' days' : null,
        ]);

        // The accounts hold the inactive products' stock too, so that is added before comparing rather
        // than reported as a difference the reader has to work out for themselves.
        $difference = round($value + $inactive - $ledger, 2);

        $reconciliation = match (true) {
            abs($difference) < 0.01 && abs($inactive) >= 0.01 => sprintf(
                'the valuation agrees with the inventory accounts, which also hold %s of stock on '
                .'deactivated products',
                number_format($inactive, 0),
            ),
            abs($difference) < 0.01 => 'the valuation agrees with the inventory accounts',
            default => sprintf(
                'the valuation and the inventory accounts differ by %s — a movement posted by hand, '
                .'or a product moved between accounts',
                number_format(abs($difference), 2),
            ),
        };

        return mb_strtoupper(implode(' · ', [count($rows).' products', ...$flags, $reconciliation]));
    }

    /**
     * Stock still sitting in these accounts on products that have been deactivated.
     *
     * The rows are active products, and deactivating one does not unpost the entries that put its stock in
     * the accounts. So this is the difference a reader would otherwise have to discover, and it is the
     * commonest one: it is what happens when somebody tidies the catalogue rather than writing stock off.
     *
     * @param  array<int, array<string, mixed>>  $valuation
     * @param  array<int, int>  $accountIds
     */
    private function inactiveStockValue(array $valuation, InventoryService $inventory, array $accountIds): float
    {
        if ($valuation === [] || $accountIds === []) {
            return 0.0;
        }

        $inactive = Product::query()
            ->where('is_active', false)
            ->whereKey(array_keys($valuation))
            ->get();

        $total = 0.0;

        foreach ($inactive as $product) {
            if (in_array($inventory->inventoryAccountId($product), $accountIds, true)) {
                $total += (float) ($valuation[$product->getKey()]['value'] ?? 0.0);
            }
        }

        return round($total, 2);
    }

    /**
     * What the inventory accounts hold, as at the date.
     *
     * There is no "no accounts" case to answer for: every product resolves to one, so the list is empty only
     * when there are no products, and the caller has already said so by then.
     *
     * @param  array<int, int>  $accountIds
     */
    private function ledgerValue(array $accountIds, string $asOf): float
    {
        return round(array_sum(app(GeneralLedgerService::class)->balancesFor($accountIds, $asOf)), 2);
    }

    /** Whether nothing has moved for long enough to be worth saying. */
    private function isStale(?string $lastMovement, Carbon $asOf): bool
    {
        if ($lastMovement === null) {
            // Never moved at all. Stale is the honest reading — the alternative is treating "no history" as
            // fresh, which would hide every product somebody set up and forgot.
            return true;
        }

        return Carbon::parse($lastMovement)->diffInDays($asOf, absolute: true) > self::STALE_DAYS;
    }

    /** The flags, in words, and a dash where there is nothing to say. */
    private function flags(bool $isBelow, bool $isStale, ?string $lastMovement): string
    {
        $flags = array_filter([
            $isBelow ? 'Reorder' : null,
            $isStale ? ($lastMovement === null ? 'Never moved' : 'No movement') : null,
        ]);

        return $flags === [] ? '—' : implode(' · ', $flags);
    }
}
