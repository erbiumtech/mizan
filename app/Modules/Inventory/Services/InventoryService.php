<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\JournalEntryService;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockLocation;
use App\Modules\Inventory\Models\StockMovement;
use App\Support\TenantTransaction;
use InvalidArgumentException;

/**
 * Stock movements with automatic balanced journal postings
 * (system-posted, like depreciation entries).
 *
 * **Every method takes an optional location**, added with `stock_locations` for
 * `docs/construction-management-plan.md` §6. Passing nothing behaves exactly as before and writes a movement with no
 * location, which is the honest state for a company that has created none. Naming one scopes both the movement and the
 * FIFO lots it consumes — stock in a warehouse cannot be consumed by an issue on a site forty miles away.
 */
class InventoryService
{
    public function __construct(
        private InventoryValuationService $valuation,
        private JournalEntryService $journalEntryService,
    ) {}

    /**
     * Receive stock: creates a purchase lot and posts
     * debit Inventory / credit Cash-Bank.
     */
    public function purchase(Product $product, float $quantity, float $unitCost, string $date, ?string $reference = null, ?StockLocation $at = null): StockMovement
    {
        if ($quantity <= 0 || $unitCost < 0) {
            throw new InvalidArgumentException('Purchase needs a positive quantity and non-negative cost.');
        }

        return TenantTransaction::run(function () use ($product, $quantity, $unitCost, $date, $reference, $at) {
            $total = round($quantity * $unitCost, 2);

            $entry = $this->postSystemEntry($date, "Stock purchase {$product->sku} ×{$quantity}", [
                ['account_id' => $this->inventoryAccountId($product), 'debit_amount' => $total, 'description' => $product->sku],
                ['account_id' => $this->cashAccountId(), 'credit_amount' => $total, 'description' => $product->sku],
            ]);

            return $product->movements()->create([
                'type' => 'purchase',
                'stock_location_id' => $at?->getKey(),
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'remaining_quantity' => $quantity,
                'movement_date' => $date,
                'reference' => $reference,
                'journal_entry_id' => $entry->id,
            ]);
        });
    }

    /**
     * Record a sale: one balanced entry with the revenue leg (at price)
     * and the COGS leg (at the valuation engine's cost).
     */
    public function sale(Product $product, float $quantity, float $unitPrice, string $date, ?string $reference = null, ?StockLocation $at = null): StockMovement
    {
        if ($quantity <= 0 || $unitPrice < 0) {
            throw new InvalidArgumentException('Sale needs a positive quantity and non-negative price.');
        }

        return TenantTransaction::run(function () use ($product, $quantity, $unitPrice, $date, $reference, $at) {
            $cogs = $this->valuation->costOfSale($product, $quantity, $at);
            $revenue = round($quantity * $unitPrice, 2);

            $entry = $this->postSystemEntry($date, "Sale {$product->sku} ×{$quantity}", [
                ['account_id' => $this->cashAccountId(), 'debit_amount' => $revenue, 'description' => "Revenue {$product->sku}"],
                ['account_id' => $this->revenueAccountId($product), 'credit_amount' => $revenue, 'description' => "Revenue {$product->sku}"],
                ['account_id' => $this->cogsAccountId($product), 'debit_amount' => $cogs, 'description' => "COGS {$product->sku}"],
                ['account_id' => $this->inventoryAccountId($product), 'credit_amount' => $cogs, 'description' => "COGS {$product->sku}"],
            ]);

            return $product->movements()->create([
                'type' => 'sale',
                'stock_location_id' => $at?->getKey(),
                'quantity' => -$quantity,
                'unit_price' => $unitPrice,
                'total_cost' => $cogs,
                'movement_date' => $date,
                'reference' => $reference,
                'journal_entry_id' => $entry->id,
            ]);
        });
    }

    /**
     * Count correction / write-off. Positive quantity needs a unit cost
     * (books like a found lot); negative writes off at valuation cost.
     */
    public function adjust(Product $product, float $quantity, string $date, ?float $unitCost = null, ?string $reference = null, ?StockLocation $at = null): StockMovement
    {
        if ($quantity == 0.0) {
            throw new InvalidArgumentException('Adjustment quantity cannot be zero.');
        }

        return TenantTransaction::run(function () use ($product, $quantity, $date, $unitCost, $reference, $at) {
            if ($quantity > 0) {
                if ($unitCost === null || $unitCost < 0) {
                    throw new InvalidArgumentException('Positive adjustments need a unit cost.');
                }

                $total = round($quantity * $unitCost, 2);

                $entry = $this->postSystemEntry($date, "Stock adjustment {$product->sku} +{$quantity}", [
                    ['account_id' => $this->inventoryAccountId($product), 'debit_amount' => $total, 'description' => $product->sku],
                    ['account_id' => $this->cogsAccountId($product), 'credit_amount' => $total, 'description' => "Adjustment {$product->sku}"],
                ]);

                return $product->movements()->create([
                    'type' => 'adjustment',
                    'stock_location_id' => $at?->getKey(),
                    'quantity' => $quantity,
                    'unit_cost' => $unitCost,
                    'remaining_quantity' => $quantity,
                    'movement_date' => $date,
                    'reference' => $reference,
                    'journal_entry_id' => $entry->id,
                ]);
            }

            $cost = $this->valuation->costOfSale($product, -$quantity, $at);

            $entry = $this->postSystemEntry($date, "Stock write-off {$product->sku} {$quantity}", [
                ['account_id' => $this->cogsAccountId($product), 'debit_amount' => $cost, 'description' => "Write-off {$product->sku}"],
                ['account_id' => $this->inventoryAccountId($product), 'credit_amount' => $cost, 'description' => "Write-off {$product->sku}"],
            ]);

            return $product->movements()->create([
                'type' => 'adjustment',
                'stock_location_id' => $at?->getKey(),
                'quantity' => $quantity,
                'total_cost' => $cost,
                'movement_date' => $date,
                'reference' => $reference,
                'journal_entry_id' => $entry->id,
            ]);
        });
    }

    protected function postSystemEntry(string $date, string $memo, array $lines): JournalEntry
    {
        $entry = $this->journalEntryService->create([
            'entry_date' => $date,
            'entry_type' => 'general',
            'memo' => $memo,
        ], array_values(array_filter($lines, fn ($l) => ($l['debit_amount'] ?? 0) > 0 || ($l['credit_amount'] ?? 0) > 0)));

        $entry->update(['status' => JournalEntry::STATUS_APPROVED, 'approved_at' => now()]);
        $this->journalEntryService->post($entry);

        return $entry;
    }

    /**
     * Where this product's stock is held in the accounts.
     *
     * **Public because a report has to reconcile against the account the posting actually used.** A product
     * that names no inventory account is not held nowhere — it is held in the default, and the stocktake
     * report (`docs/reports-expansion-plan.md` Phase 2.4) was built on the opposite assumption until its
     * tests said otherwise. Two copies of this fallback would be two answers to "which account holds this",
     * and the report would reconcile against one while the ledger held the other.
     */
    public function inventoryAccountId(Product $product): int
    {
        return $product->inventory_account_id ?? $this->accountId('1300');
    }

    protected function cogsAccountId(Product $product): int
    {
        return $product->cogs_account_id ?? $this->accountId('5050');
    }

    protected function revenueAccountId(Product $product): int
    {
        return $product->revenue_account_id ?? $this->accountId('4200');
    }

    protected function cashAccountId(): int
    {
        return $this->accountId('1100');
    }

    /**
     * An account id from its code, looked up once per request.
     *
     * **Memoised, and the stocktake report is what made it matter.** A code maps to an id for the life of a
     * request, and this was a query every time — once per posting, which is unremarkable, and once per
     * *product* when a report resolves where each one's stock is held. Ten products with no explicit account
     * meant ten identical lookups; the report's query-count test is what noticed.
     *
     * Per instance rather than static, so a test that swaps the chart of accounts underneath gets a fresh
     * service and a fresh answer rather than a cached id from another company's chart.
     *
     * @var array<string, int>
     */
    private array $accountIds = [];

    protected function accountId(string $code): int
    {
        return $this->accountIds[$code] ??= Account::where('code', $code)->firstOrFail()->id;
    }
}
