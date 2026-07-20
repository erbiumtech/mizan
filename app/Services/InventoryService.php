<?php

namespace App\Services;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Stock movements with automatic balanced journal postings
 * (system-posted, like depreciation entries).
 */
class InventoryService
{
    public function __construct(
        private InventoryValuationService $valuation,
        private JournalEntryService $journalEntryService,
    ) {
    }

    /**
     * Receive stock: creates a purchase lot and posts
     * debit Inventory / credit Cash-Bank.
     */
    public function purchase(Product $product, float $quantity, float $unitCost, string $date, ?string $reference = null): StockMovement
    {
        if ($quantity <= 0 || $unitCost < 0) {
            throw new InvalidArgumentException('Purchase needs a positive quantity and non-negative cost.');
        }

        return DB::transaction(function () use ($product, $quantity, $unitCost, $date, $reference) {
            $total = round($quantity * $unitCost, 2);

            $entry = $this->postSystemEntry($date, "Stock purchase {$product->sku} ×{$quantity}", [
                ['account_id' => $this->inventoryAccountId($product), 'debit_amount' => $total, 'description' => $product->sku],
                ['account_id' => $this->cashAccountId(), 'credit_amount' => $total, 'description' => $product->sku],
            ]);

            return $product->movements()->create([
                'type' => 'purchase',
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
    public function sale(Product $product, float $quantity, float $unitPrice, string $date, ?string $reference = null): StockMovement
    {
        if ($quantity <= 0 || $unitPrice < 0) {
            throw new InvalidArgumentException('Sale needs a positive quantity and non-negative price.');
        }

        return DB::transaction(function () use ($product, $quantity, $unitPrice, $date, $reference) {
            $cogs = $this->valuation->costOfSale($product, $quantity);
            $revenue = round($quantity * $unitPrice, 2);

            $entry = $this->postSystemEntry($date, "Sale {$product->sku} ×{$quantity}", [
                ['account_id' => $this->cashAccountId(), 'debit_amount' => $revenue, 'description' => "Revenue {$product->sku}"],
                ['account_id' => $this->revenueAccountId($product), 'credit_amount' => $revenue, 'description' => "Revenue {$product->sku}"],
                ['account_id' => $this->cogsAccountId($product), 'debit_amount' => $cogs, 'description' => "COGS {$product->sku}"],
                ['account_id' => $this->inventoryAccountId($product), 'credit_amount' => $cogs, 'description' => "COGS {$product->sku}"],
            ]);

            return $product->movements()->create([
                'type' => 'sale',
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
    public function adjust(Product $product, float $quantity, string $date, ?float $unitCost = null, ?string $reference = null): StockMovement
    {
        if ($quantity == 0.0) {
            throw new InvalidArgumentException('Adjustment quantity cannot be zero.');
        }

        return DB::transaction(function () use ($product, $quantity, $date, $unitCost, $reference) {
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
                    'quantity' => $quantity,
                    'unit_cost' => $unitCost,
                    'remaining_quantity' => $quantity,
                    'movement_date' => $date,
                    'reference' => $reference,
                    'journal_entry_id' => $entry->id,
                ]);
            }

            $cost = $this->valuation->costOfSale($product, -$quantity);

            $entry = $this->postSystemEntry($date, "Stock write-off {$product->sku} {$quantity}", [
                ['account_id' => $this->cogsAccountId($product), 'debit_amount' => $cost, 'description' => "Write-off {$product->sku}"],
                ['account_id' => $this->inventoryAccountId($product), 'credit_amount' => $cost, 'description' => "Write-off {$product->sku}"],
            ]);

            return $product->movements()->create([
                'type' => 'adjustment',
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

    protected function inventoryAccountId(Product $product): int
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

    protected function accountId(string $code): int
    {
        return Account::where('code', $code)->firstOrFail()->id;
    }
}
