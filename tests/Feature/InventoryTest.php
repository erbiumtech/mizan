<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\JournalEntryLine;
use App\Models\Product;
use App\Services\InventoryService;
use App\Services\InventoryValuationService;
use InvalidArgumentException;
use Tests\AccountingTestCase;

class InventoryTest extends AccountingTestCase
{
    private InventoryService $inventory;

    private InventoryValuationService $valuation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inventory = app(InventoryService::class);
        $this->valuation = app(InventoryValuationService::class);
    }

    private function makeProduct(string $method): Product
    {
        return Product::create([
            'sku' => strtoupper($method).'-TEST-01',
            'name' => ucfirst($method).' Test Product',
            'unit' => 'pcs',
            'valuation_method' => $method,
        ]);
    }

    private function ledgerBalance(string $code): float
    {
        $account = Account::where('code', $code)->firstOrFail();

        $sums = JournalEntryLine::where('account_id', $account->id)
            ->whereHas('journalEntry', fn ($q) => $q->where('status', 'posted'))
            ->selectRaw('COALESCE(SUM(debit_amount),0) as d, COALESCE(SUM(credit_amount),0) as c')
            ->first();

        return round((float) $sums->d - (float) $sums->c, 2);
    }

    public function test_fifo_consumes_oldest_lot_first(): void
    {
        $product = $this->makeProduct('fifo');

        $this->inventory->purchase($product, 10, 100, '2026-08-01');
        $this->inventory->purchase($product, 10, 120, '2026-08-05');

        $movement = $this->inventory->sale($product, 12, 200, '2026-08-10');

        // 10 @ 100 + 2 @ 120
        $this->assertSame(1240.0, (float) $movement->total_cost);
        $this->assertSame(8.0, $this->valuation->onHand($product));
        $this->assertSame(960.0, $this->valuation->stockValue($product));
    }

    public function test_lifo_consumes_newest_lot_first(): void
    {
        $product = $this->makeProduct('lifo');

        $this->inventory->purchase($product, 10, 100, '2026-08-01');
        $this->inventory->purchase($product, 10, 120, '2026-08-05');

        $movement = $this->inventory->sale($product, 12, 200, '2026-08-10');

        // 10 @ 120 + 2 @ 100
        $this->assertSame(1400.0, (float) $movement->total_cost);
        $this->assertSame(800.0, $this->valuation->stockValue($product));
    }

    public function test_average_cost_recomputes_on_each_purchase(): void
    {
        $product = $this->makeProduct('average');

        $this->inventory->purchase($product, 10, 100, '2026-08-01');
        $this->assertSame(100.0, $this->valuation->averageCost($product));

        $this->inventory->purchase($product, 10, 120, '2026-08-05');
        $this->assertSame(110.0, $this->valuation->averageCost($product));

        $movement = $this->inventory->sale($product, 12, 200, '2026-08-10');

        // 12 @ 110 weighted average
        $this->assertSame(1320.0, (float) $movement->total_cost);
        $this->assertSame(880.0, $this->valuation->stockValue($product));
    }

    public function test_sale_posts_balanced_entry_hitting_cogs_and_inventory(): void
    {
        $product = $this->makeProduct('fifo');

        $this->inventory->purchase($product, 10, 100, '2026-08-01');
        $movement = $this->inventory->sale($product, 4, 250, '2026-08-10');

        $entry = $movement->journalEntry;
        $this->assertNotNull($entry);
        $this->assertSame('posted', $entry->status);

        $debits = (float) $entry->lines()->sum('debit_amount');
        $credits = (float) $entry->lines()->sum('credit_amount');
        $this->assertSame($debits, $credits);

        // Revenue 1000 credited to 4200, COGS 400 debited to 5050.
        $this->assertSame(-1000.0, $this->ledgerBalance('4200'));
        $this->assertSame(400.0, $this->ledgerBalance('5050'));
    }

    public function test_stock_value_reconciles_with_inventory_ledger(): void
    {
        $product = $this->makeProduct('fifo');

        $this->inventory->purchase($product, 10, 100, '2026-08-01');
        $this->inventory->purchase($product, 5, 140, '2026-08-05');
        $this->inventory->sale($product, 8, 300, '2026-08-10');

        $this->assertSame($this->valuation->stockValue($product), $this->ledgerBalance('1300'));
    }

    public function test_negative_stock_is_blocked(): void
    {
        $product = $this->makeProduct('fifo');
        $this->inventory->purchase($product, 5, 100, '2026-08-01');

        $this->expectException(InvalidArgumentException::class);
        $this->inventory->sale($product, 6, 200, '2026-08-10');
    }

    public function test_negative_adjustment_writes_off_at_valuation_cost(): void
    {
        $product = $this->makeProduct('fifo');
        $this->inventory->purchase($product, 10, 100, '2026-08-01');

        $movement = $this->inventory->adjust($product, -3, '2026-08-15', null, 'damaged');

        $this->assertSame(300.0, (float) $movement->total_cost);
        $this->assertSame(7.0, $this->valuation->onHand($product));
        $this->assertSame(300.0, $this->ledgerBalance('5050'));
    }

    public function test_positive_adjustment_requires_unit_cost(): void
    {
        $product = $this->makeProduct('fifo');

        $this->expectException(InvalidArgumentException::class);
        $this->inventory->adjust($product, 3, '2026-08-15');
    }
}
