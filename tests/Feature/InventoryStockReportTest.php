<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Inventory\Filament\Pages\StockOnHand as StockOnHandPage;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Services\InventoryValuationService;
use App\Support\Reporting\ReportPaneRenderer;
use App\Support\Reporting\ReportRenderers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Stock on Hand — `docs/reports-expansion-plan.md` Phase 2.4.
 *
 * Three claims, in the order they matter:
 *
 *  - **The batched valuation agrees with the per-product one, product by product.** That is the
 *    specification rather than a nicety: `valuationForAll()` exists because calling `onHand()`,
 *    `stockValue()` and `averageCost()` down a catalogue is 3n+ queries, and two ways of computing one
 *    figure is a drift waiting to happen. Phase 1.1's general-ledger rewrite earned the same test for the
 *    same reason, and that one found its own limit by mutation.
 *  - **It reconciles to the inventory accounts.** Phase 2's rule, and unlike leave liability and unbilled
 *    WIP this report has something real to tie to.
 *  - **The flags describe the rows they are on**, including the two cases that would otherwise look alike:
 *    a product that has never moved, and one that moved long ago.
 *
 * `InventoryTest` owns FIFO, LIFO and the average-cost arithmetic. None of it is restated here.
 */
class InventoryStockReportTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private const AS_OF = '2026-08-20';

    private InventoryService $inventory;

    private InventoryValuationService $valuation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'stock@test.local'));
        $company = $this->setCurrentTenant();

        foreach (['inventory', 'accounting'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $company->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();

        $this->inventory = app(InventoryService::class);
        $this->valuation = app(InventoryValuationService::class);
    }

    // ────────────────────────────────────────────────────────────── fixtures ──

    private function product(string $sku, array $attributes = []): Product
    {
        return Product::create(array_merge([
            'sku' => $sku,
            'name' => $sku.' widget',
            'unit' => 'pcs',
            'valuation_method' => 'average',
            'is_active' => true,
        ], $attributes));
    }

    private function account(string $code): Account
    {
        return Account::where('code', $code)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function report(?string $asOf = null): array
    {
        $payload = ReportRenderers::render('StockOnHand', $asOf ?? self::AS_OF, false, []);

        $this->assertNotNull($payload, 'no renderer is registered for StockOnHand');

        return $payload;
    }

    private function row(array $payload, string $sku): array
    {
        foreach ($payload['rows'] as $row) {
            if (str_starts_with($row[0], $sku)) {
                return $row;
            }
        }

        $this->fail("no row for [{$sku}] in ".collect($payload['rows'])->flatten()->implode(' | '));
    }

    // ─────────────────────────────────────────────────────── the equivalence ──

    /**
     * The batched valuation equals the per-product one, for every product and every figure.
     *
     * The specification for `valuationForAll()`. A mixture of purchases, sales and a product that has been
     * cleared out entirely, because the last of those is where the division guard lives.
     */
    public function test_the_batched_valuation_agrees_with_the_per_product_one(): void
    {
        $fifo = $this->product('FIFO-1', ['valuation_method' => 'fifo']);
        $this->inventory->purchase($fifo, 10, 100, '2026-08-01');
        $this->inventory->purchase($fifo, 10, 120, '2026-08-05');
        $this->inventory->sale($fifo, 12, 200, '2026-08-10');

        $average = $this->product('AVG-1');
        $this->inventory->purchase($average, 5, 50, '2026-08-02');

        // Bought and entirely sold: nought on hand, which is where averageCost() refuses to divide.
        $emptied = $this->product('GONE-1');
        $this->inventory->purchase($emptied, 4, 25, '2026-08-03');
        $this->inventory->sale($emptied, 4, 40, '2026-08-06');

        $batched = $this->valuation->valuationForAll(self::AS_OF);

        foreach ([$fifo, $average, $emptied] as $product) {
            $figures = $batched[$product->getKey()];

            $this->assertSame(
                $this->valuation->onHand($product),
                $figures['on_hand'],
                "on hand disagrees for {$product->sku}",
            );
            $this->assertSame(
                $this->valuation->stockValue($product),
                $figures['value'],
                "value disagrees for {$product->sku}",
            );
            $this->assertSame(
                $this->valuation->averageCost($product),
                $figures['average_cost'],
                "average cost disagrees for {$product->sku}",
            );
        }
    }

    /** And it is one query for the catalogue, not three per product. */
    public function test_the_report_does_not_query_per_product(): void
    {
        foreach (range(1, 10) as $i) {
            $this->inventory->purchase($this->product('SKU-'.$i), 5, 100 + $i, '2026-08-0'.min(9, $i));
        }

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $payload = $this->report();

        $this->assertCount(10, $payload['rows']);
        $this->assertLessThanOrEqual(
            8,
            $queries,
            "the report ran {$queries} queries for ten products, which is per-product rather than aggregate",
        );
    }

    /** The date is honoured: a movement after it is not in the balance. */
    public function test_it_values_stock_as_at_the_date(): void
    {
        $product = $this->product('SKU-1');
        $this->inventory->purchase($product, 10, 100, '2026-08-01');
        $this->inventory->purchase($product, 10, 100, '2026-09-01');

        $this->assertSame('10.00', $this->row($this->report(), 'SKU-1')[1]);
        $this->assertSame('20.00', $this->row($this->report('2026-09-30'), 'SKU-1')[1]);
    }

    // ───────────────────────────────────────────────────── the reconciliation ──

    /**
     * The valuation equals what the inventory accounts hold.
     *
     * Purchases post to the product's inventory account and sales take cost out of it, so the account and
     * the valuation are two independent statements of one figure — which is exactly what Phase 2 asks a
     * report of this kind to demonstrate.
     */
    public function test_the_valuation_agrees_with_the_inventory_accounts(): void
    {
        $product = $this->product('SKU-1', ['inventory_account_id' => $this->account('1300')->id]);

        $this->inventory->purchase($product, 10, 100, '2026-08-01');
        $this->inventory->sale($product, 4, 200, '2026-08-05');

        $payload = $this->report();

        $this->assertSame(600.0, $payload['tiles'][0]['value'], 'six left at 100');
        $this->assertSame($payload['tiles'][0]['value'], $payload['tiles'][1]['value']);
        $this->assertStringContainsString('THE VALUATION AGREES WITH THE INVENTORY ACCOUNTS', $payload['note']);
    }

    /**
     * A product naming no inventory account still reconciles, because its stock is in the default one.
     *
     * This report was built on the opposite assumption — that unmapped stock was in no account and had to be
     * explained as a difference — and this test is what disproved it. `InventoryService` resolves the account
     * as "the product's, else 1300" when posting, so there is no such thing as stock outside the accounts,
     * and the report resolves it the same way rather than keeping a second answer.
     */
    public function test_a_product_naming_no_account_still_reconciles_through_the_default(): void
    {
        $mapped = $this->product('SKU-1', ['inventory_account_id' => $this->account('1300')->id]);
        $this->inventory->purchase($mapped, 10, 100, '2026-08-01');

        $unmapped = $this->product('SKU-2');
        $this->inventory->purchase($unmapped, 5, 40, '2026-08-02');

        $payload = $this->report();

        $this->assertSame(1_200.0, $payload['tiles'][0]['value'], 'the valuation carries both');
        $this->assertSame(1_200.0, $payload['tiles'][1]['value'], 'and so do the accounts');
        $this->assertStringContainsString('AGREES WITH THE INVENTORY ACCOUNTS', $payload['note']);
    }

    /**
     * Stock on a deactivated product is named as the difference rather than treated as a fault.
     *
     * The rows are active products; deactivating one does not unpost the entries that put its stock in the
     * accounts. So this is the commonest difference of all — somebody tidying the catalogue rather than
     * writing stock off — and it has to read as an explanation, not an alarm.
     */
    public function test_stock_on_a_deactivated_product_is_explained(): void
    {
        $live = $this->product('SKU-1');
        $this->inventory->purchase($live, 10, 100, '2026-08-01');

        $retired = $this->product('SKU-2');
        $this->inventory->purchase($retired, 5, 40, '2026-08-02');
        $retired->update(['is_active' => false]);

        $payload = $this->report();

        $this->assertSame(1_000.0, $payload['tiles'][0]['value'], 'only the active product is valued');
        $this->assertSame(1_200.0, $payload['tiles'][1]['value'], 'the accounts still hold both');
        $this->assertStringContainsString('AGREES WITH THE INVENTORY ACCOUNTS', $payload['note']);
        $this->assertStringContainsString('200 OF STOCK ON DEACTIVATED PRODUCTS', $payload['note']);
        $this->assertStringNotContainsString('DIFFER BY', $payload['note']);
    }

    /** A hand-posted movement against the account with everything mapped *is* a discrepancy. */
    public function test_a_hand_posted_entry_is_reported_as_a_difference(): void
    {
        $product = $this->product('SKU-1', ['inventory_account_id' => $this->account('1300')->id]);
        $this->inventory->purchase($product, 10, 100, '2026-08-01');

        // Somebody writes off 250 against the inventory account without touching stock.
        $entry = app(\App\Modules\Accounting\Services\JournalEntryService::class)->create(
            ['entry_date' => '2026-08-10', 'entry_type' => 'general', 'memo' => 'Stock write-down'],
            [
                ['account_id' => $this->account('5900')->id, 'debit_amount' => 250],
                ['account_id' => $this->account('1300')->id, 'credit_amount' => 250],
            ],
        );
        $entry->update(['status' => \App\Modules\Accounting\Models\JournalEntry::STATUS_APPROVED, 'approved_at' => now()]);
        app(\App\Modules\Accounting\Services\JournalEntryService::class)->post($entry->fresh());

        $payload = $this->report();

        $this->assertStringContainsString('DIFFER BY 250.00', $payload['note']);
        $this->assertStringContainsString('POSTED BY HAND', $payload['note']);
    }

    /**
     * There is no "nothing to reconcile to" case, and that is worth pinning.
     *
     * With no product naming an account, every one of them still resolves to the default — so the comparison
     * is always available. An earlier version of this report had a branch saying otherwise; it was
     * unreachable, and unreachable branches about money are worse than absent ones because they read as
     * having been considered.
     */
    public function test_the_comparison_is_available_even_with_nothing_explicitly_mapped(): void
    {
        $this->inventory->purchase($this->product('SKU-1'), 10, 100, '2026-08-01');

        $payload = $this->report();

        $this->assertSame(1_000.0, $payload['tiles'][1]['value']);
        $this->assertStringContainsString('AGREES WITH THE INVENTORY ACCOUNTS', $payload['note']);
        $this->assertStringNotContainsString('NOTHING TO RECONCILE', $payload['note']);
    }

    // ─────────────────────────────────────────────────────────────── the flags ──

    /** At or below the reorder level, and the level itself is on the row. */
    public function test_it_flags_stock_at_or_below_the_reorder_level(): void
    {
        $low = $this->product('LOW-1', ['reorder_level' => 10]);
        $this->inventory->purchase($low, 10, 100, '2026-08-01');

        $fine = $this->product('OK-1', ['reorder_level' => 5]);
        $this->inventory->purchase($fine, 50, 100, '2026-08-01');

        $this->assertStringContainsString('Reorder', $this->row($this->report(), 'LOW-1')[5]);
        $this->assertSame('—', $this->row($this->report(), 'OK-1')[5]);
        $this->assertStringContainsString('1 AT OR BELOW REORDER LEVEL', $this->report()['note']);
    }

    /**
     * A reorder level of nought means there is no level, not a level of nought.
     *
     * The column defaults to `0`, so this is every product nobody has set one on — and treating nought as a
     * threshold flagged every sold-out product, since `0 <= 0`. The dash in the level column and the absence
     * of the flag are the same fact stated twice, deliberately: one says there is no rule, the other that no
     * rule fired.
     */
    public function test_a_reorder_level_of_nought_means_there_is_no_level(): void
    {
        // Sold out, which is where a nought threshold would have fired.
        $product = $this->product('SKU-1');
        $this->inventory->purchase($product, 1, 100, '2026-08-01');
        $this->inventory->sale($product, 1, 200, '2026-08-02');

        $row = $this->row($this->report(), 'SKU-1');

        $this->assertSame('0.00', $row[1], 'nothing on hand');
        $this->assertSame('—', $row[4], 'no level to show');
        $this->assertStringNotContainsString('Reorder', $row[5]);
    }

    /** Stale stock is flagged, and fresh stock is not. */
    public function test_it_flags_stock_that_has_not_moved(): void
    {
        $stale = $this->product('OLD-1');
        $this->inventory->purchase($stale, 10, 100, '2026-01-05');

        $fresh = $this->product('NEW-1');
        $this->inventory->purchase($fresh, 10, 100, '2026-08-15');

        $this->assertStringContainsString('No movement', $this->row($this->report(), 'OLD-1')[5]);
        $this->assertSame('—', $this->row($this->report(), 'NEW-1')[5]);
    }

    /**
     * A product that has never moved says so, rather than reading as fresh.
     *
     * "No history" and "moved yesterday" are opposite facts, and treating the first as fresh would hide
     * every product somebody set up and forgot — which is the population the flag is most useful for.
     */
    public function test_a_product_that_never_moved_is_named_as_such(): void
    {
        $this->product('UNUSED-1');

        $row = $this->row($this->report(), 'UNUSED-1');

        // And *only* that: with no reorder level set, nothing on hand must not also read as needing a
        // reorder, which is what a nought threshold did.
        $this->assertSame('Never moved', $row[5]);
        $this->assertSame('0.00', $row[1]);
    }

    /** Both flags at once is one row, which is the case the plan wanted them on one report for. */
    public function test_both_flags_can_appear_on_one_row(): void
    {
        $product = $this->product('BOTH-1', ['reorder_level' => 20]);
        $this->inventory->purchase($product, 10, 100, '2026-01-05');

        $flags = $this->row($this->report(), 'BOTH-1')[5];

        $this->assertStringContainsString('Reorder', $flags);
        $this->assertStringContainsString('No movement', $flags);
    }

    // ─────────────────────────────────────────────────────── how it is stated ──

    /** Nothing on hand has no average cost — a nought there would claim the stock is free. */
    public function test_a_product_with_nothing_on_hand_shows_no_average_cost(): void
    {
        $product = $this->product('GONE-1');
        $this->inventory->purchase($product, 4, 25, '2026-08-03');
        $this->inventory->sale($product, 4, 40, '2026-08-06');

        $row = $this->row($this->report(), 'GONE-1');

        $this->assertSame('0.00', $row[1]);
        $this->assertSame('—', $row[2]);
    }

    /** An inactive product is off the report entirely. */
    public function test_an_inactive_product_is_not_listed(): void
    {
        $product = $this->product('SKU-1');
        $this->inventory->purchase($product, 10, 100, '2026-08-01');
        $product->update(['is_active' => false]);

        $payload = $this->report();

        $this->assertSame([], $payload['rows']);
        $this->assertSame('NO ACTIVE PRODUCT', $payload['note']);
    }

    /** The record row foots the value column. */
    public function test_the_record_row_foots_the_value(): void
    {
        $this->inventory->purchase($this->product('SKU-1'), 10, 100, '2026-08-01');
        $this->inventory->purchase($this->product('SKU-2'), 5, 40, '2026-08-02');

        $payload = $this->report();

        $rows = array_sum(array_map(
            fn (array $row): float => (float) str_replace(',', '', $row[3]),
            $payload['rows'],
        ));

        $this->assertSame((float) str_replace(',', '', $payload['footer'][3]), $rows);
        $this->assertSame(1_200.0, $rows);
    }

    // ──────────────────────────────────────────── the page and the pane ──

    /** Two screens, one payload. */
    public function test_the_report_states_the_same_thing_on_its_page_as_in_the_pane(): void
    {
        Gate::before(fn () => true);
        $this->inventory->purchase($this->product('SKU-1'), 10, 100, '2026-08-01');

        $onThePage = Livewire::test(StockOnHandPage::class, ['asOf' => self::AS_OF])
            ->assertSuccessful()
            ->instance()
            ->statement();

        $this->assertSame($onThePage, app(ReportPaneRenderer::class)->for('StockOnHand', self::AS_OF, false, []));
    }

    /** And on `ReportView`. */
    public function test_the_report_is_gated_on_report_view(): void
    {
        $this->assertTrue(StockOnHandPage::canAccess(), 'an administrator should be able to open it');

        $this->actingAs(\App\Modules\Core\Models\User::factory()->create(['status' => 1]));

        $this->assertFalse(StockOnHandPage::canAccess());
    }
}
