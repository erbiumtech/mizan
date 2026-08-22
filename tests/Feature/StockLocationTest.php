<?php

namespace Tests\Feature;

use App\Modules\Core\Models\CompanyModule;
use App\Modules\Inventory\Filament\Resources\StockLocations\Pages\ListStockLocations;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockLocation;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Services\InventoryValuationService;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Where stock is — `docs/construction-management-plan.md` §6, `docs/retail-stores-pos-plan.md` §2.1.
 *
 * **The cross-plan migration both plans were waiting on.** Two plans found the same gap: `stock_movements` had no
 * location, so `onHand()` summed every movement for a product everywhere and "what steel is on site" had no answer.
 * Each plan's own fix would have hurt the other — retail's `stock_movements.store_id -> stores` would have made a
 * building site either a fake shop or a second nullable location column, "wrong at every location and correct in total".
 * The location belongs to Inventory, and each module points at it.
 *
 * Five properties carry this file:
 *
 *  - **Nothing changes for a company with no locations.** Every existing caller passes no location and gets the
 *    company-wide figure it always got, which is the right answer when there is one place.
 *  - **A location scopes on-hand, the valuation and the FIFO lots**, and the last of those is the one that would have
 *    been quietly wrong: stock in a warehouse cannot be consumed by an issue on a site forty miles away.
 *  - **The type enum is expanded once for both plans.** `adjustment` keeps its meaning — the *unexplained* difference —
 *    which is the whole reason `issue`, `return`, `transfer`, `waste` and `count_adjustment` were added beside it.
 *  - **Null means "not tracked by location"**, not "unknown", and `onHandByLocation()` shows it rather than folding it
 *    into a location.
 *  - **There is no second location column anywhere**, which is the thing §6 spends a paragraph refusing.
 */
class StockLocationTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Product $rebar;

    private StockLocation $warehouse;

    private StockLocation $site;

    private InventoryValuationService $valuation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'locations@test.local'));
        $this->setCurrentTenant();

        foreach (['accounting', 'inventory'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $this->tenant->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();

        $this->rebar = Product::create([
            'sku' => 'REBAR-16',
            'name' => 'Rebar 16mm',
            'unit_price' => 300,
            'valuation_method' => Product::METHOD_FIFO,
        ]);

        $this->warehouse = StockLocation::create(['code' => 'MAIN', 'name' => 'Main store']);
        $this->site = StockLocation::create([
            'code' => 'SITE-01', 'name' => 'Tower site store', 'kind' => StockLocation::KIND_SITE,
        ]);

        $this->valuation = app(InventoryValuationService::class);
    }

    /** A lot at a location, without the journal posting `InventoryService::purchase()` would add. */
    private function lot(StockLocation $at, float $quantity, float $unitCost, string $date = '2026-08-01'): StockMovement
    {
        return StockMovement::create([
            'product_id' => $this->rebar->getKey(),
            'stock_location_id' => $at->getKey(),
            'type' => 'purchase',
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'remaining_quantity' => $quantity,
            'movement_date' => $date,
        ]);
    }

    // ------------------------------------------------------------------ the register

    public function test_a_location_defaults_to_a_warehouse_and_is_in_use(): void
    {
        $this->assertSame(StockLocation::KIND_WAREHOUSE, $this->warehouse->kind);
        $this->assertTrue($this->warehouse->is_active);
        $this->assertFalse($this->warehouse->isSite());
        $this->assertTrue($this->site->isSite());
    }

    /** All five kinds both plans need are one enum, which is what stops each building its own table. */
    public function test_every_kind_both_plans_need_exists(): void
    {
        foreach ([
            StockLocation::KIND_WAREHOUSE,
            StockLocation::KIND_SHOP,
            StockLocation::KIND_SITE,
            StockLocation::KIND_VAN,
            StockLocation::KIND_TRANSIT,
        ] as $kind) {
            $this->assertArrayHasKey($kind, StockLocation::KINDS);
        }
    }

    /**
     * **There is no second location column**, which is the alternative §6 spends a paragraph refusing.
     *
     * A `store_id` beside `stock_location_id` would make on-hand a sum over two nullable dimensions — "wrong at every
     * location and correct in total, which is the hardest class of wrong to notice".
     */
    public function test_there_is_exactly_one_location_column_on_a_movement(): void
    {
        $this->assertTrue(Schema::hasColumn('stock_movements', 'stock_location_id'));
        $this->assertFalse(Schema::hasColumn('stock_movements', 'store_id'));
        $this->assertFalse(Schema::hasColumn('stock_movements', 'location_id'));
    }

    /** The enum was expanded once, for both plans. */
    public function test_the_movement_types_cover_both_plans(): void
    {
        foreach ([
            StockMovement::TYPE_ISSUE,
            StockMovement::TYPE_RETURN,
            StockMovement::TYPE_TRANSFER,
            StockMovement::TYPE_WASTE,
            StockMovement::TYPE_COUNT_ADJUSTMENT,
        ] as $type) {
            $movement = StockMovement::create([
                'product_id' => $this->rebar->getKey(),
                'stock_location_id' => $this->warehouse->getKey(),
                'type' => $type,
                'quantity' => 1,
                'movement_date' => '2026-08-01',
            ]);

            $this->assertSame($type, $movement->refresh()->type);
        }
    }

    // ------------------------------------------------------------------ on hand, per location

    /** **The question §6 exists to make answerable.** */
    public function test_on_hand_can_be_asked_of_one_location(): void
    {
        $this->lot($this->warehouse, 100, 250);
        $this->lot($this->site, 40, 260);

        $this->assertSame(140.0, $this->valuation->onHand($this->rebar), 'everywhere, which is the default');
        $this->assertSame(100.0, $this->valuation->onHand($this->rebar, $this->warehouse));
        $this->assertSame(40.0, $this->valuation->onHand($this->rebar, $this->site));
    }

    /** Nothing changes for a caller that does not care: the company-wide figure is still the default. */
    public function test_an_unlocated_movement_still_counts_in_the_total(): void
    {
        StockMovement::create([
            'product_id' => $this->rebar->getKey(),
            'type' => 'purchase',
            'quantity' => 10,
            'unit_cost' => 250,
            'remaining_quantity' => 10,
            'movement_date' => '2026-08-01',
        ]);

        $this->assertSame(10.0, $this->valuation->onHand($this->rebar));
        $this->assertSame(0.0, $this->valuation->onHand($this->rebar, $this->warehouse));
    }

    /**
     * Unlocated stock is shown as its own row rather than folded into a location.
     *
     * "Correct in total and wrong at every location" is the failure §6 names, and hiding the unlocated remainder inside
     * one of the locations is how a report produces it.
     */
    public function test_on_hand_by_location_shows_the_unlocated_separately(): void
    {
        $this->lot($this->warehouse, 100, 250);
        StockMovement::create([
            'product_id' => $this->rebar->getKey(),
            'type' => 'purchase',
            'quantity' => 7,
            'unit_cost' => 250,
            'remaining_quantity' => 7,
            'movement_date' => '2026-08-01',
        ]);

        $byLocation = $this->valuation->onHandByLocation($this->rebar);

        $this->assertSame(100.0, $byLocation[$this->warehouse->getKey()]);
        $this->assertSame(7.0, $byLocation[''], 'the unlocated remainder, visible rather than absorbed');
    }

    /** A location holding nothing is not listed, because a row of zero is noise on a report of where stock is. */
    public function test_a_location_with_nothing_is_not_listed(): void
    {
        $this->lot($this->warehouse, 100, 250);

        $this->assertArrayNotHasKey($this->site->getKey(), $this->valuation->onHandByLocation($this->rebar));
    }

    // ------------------------------------------------------------------ the valuation, per location

    public function test_the_stock_value_and_average_cost_scope_to_a_location(): void
    {
        $this->lot($this->warehouse, 100, 250);
        $this->lot($this->site, 40, 300);

        $this->assertSame(25_000.0, $this->valuation->stockValue($this->rebar, $this->warehouse));
        $this->assertSame(12_000.0, $this->valuation->stockValue($this->rebar, $this->site));
        $this->assertSame(250.0, $this->valuation->averageCost($this->rebar, $this->warehouse));
        $this->assertSame(300.0, $this->valuation->averageCost($this->rebar, $this->site));
        // And everywhere, the blend — which is what a company with one place has always got.
        $this->assertSame(37_000.0, $this->valuation->stockValue($this->rebar));
    }

    /**
     * **The one that would have been quietly wrong: FIFO lots are scoped too.**
     *
     * Unscoped, the cheapest lot anywhere in the company prices every issue — so an issue on site would consume a
     * warehouse lot it could never physically have taken, and both locations' valuations would drift with nothing
     * disagreeing.
     */
    public function test_lot_consumption_only_takes_lots_at_that_location(): void
    {
        $cheap = $this->lot($this->warehouse, 100, 200, '2026-07-01');
        $dear = $this->lot($this->site, 40, 300, '2026-08-01');

        $cost = $this->valuation->costOfSale($this->rebar, 10, $this->site);

        $this->assertSame(3_000.0, $cost, 'the site lot at 300, not the older warehouse lot at 200');
        $this->assertEquals(100, $cheap->refresh()->remaining_quantity, 'the warehouse lot is untouched');
        $this->assertEquals(30, $dear->refresh()->remaining_quantity);
    }

    /** And the sufficiency check is scoped with it, or an issue could be priced out of stock somewhere else. */
    public function test_insufficient_stock_at_a_location_is_refused_even_when_the_company_has_plenty(): void
    {
        $this->lot($this->warehouse, 100, 200);
        $this->lot($this->site, 5, 300);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at that location');

        $this->valuation->costOfSale($this->rebar, 10, $this->site);
    }

    /** Unscoped, the old behaviour is exactly preserved: the whole company's stock, oldest lot first. */
    public function test_unscoped_consumption_behaves_as_it_always_did(): void
    {
        $old = $this->lot($this->warehouse, 10, 200, '2026-07-01');
        $this->lot($this->site, 10, 300, '2026-08-01');

        $this->assertSame(2_000.0, $this->valuation->costOfSale($this->rebar, 10));
        $this->assertEquals(0, $old->refresh()->remaining_quantity);
    }

    // ------------------------------------------------------------------ the service

    /** `InventoryService` takes a location on every method and writes it. */
    public function test_the_inventory_service_records_the_location(): void
    {
        $inventory = app(InventoryService::class);

        $purchase = $inventory->purchase($this->rebar, 20, 250, '2026-08-01', 'PO-1', $this->site);

        $this->assertSame($this->site->getKey(), $purchase->stock_location_id);
        $this->assertSame(20.0, $this->valuation->onHand($this->rebar, $this->site));

        $sale = $inventory->sale($this->rebar, 5, 400, '2026-08-02', 'INV-1', $this->site);

        $this->assertSame($this->site->getKey(), $sale->stock_location_id);
        $this->assertSame(15.0, $this->valuation->onHand($this->rebar, $this->site));
    }

    /** And passing none is still allowed, which is what keeps every existing caller working unchanged. */
    public function test_the_inventory_service_still_works_without_a_location(): void
    {
        $purchase = app(InventoryService::class)->purchase($this->rebar, 20, 250, '2026-08-01');

        $this->assertNull($purchase->stock_location_id);
        $this->assertSame(20.0, $this->valuation->onHand($this->rebar));
    }

    /** A write-off takes its cost from the location it is written off at. */
    public function test_an_adjustment_is_valued_at_its_own_location(): void
    {
        $this->lot($this->warehouse, 100, 200);
        $this->lot($this->site, 10, 350);

        $writeOff = app(InventoryService::class)->adjust($this->rebar, -4, '2026-08-05', null, 'damaged', $this->site);

        $this->assertEquals(1_400, $writeOff->total_cost, '4 at the site rate of 350, not the warehouse 200');
        $this->assertSame($this->site->getKey(), $writeOff->stock_location_id);
    }

    // ------------------------------------------------------------------ the screen

    public function test_the_register_renders_and_marks_a_site_store(): void
    {
        Livewire::test(ListStockLocations::class)
            ->assertCanSeeTableRecords([$this->warehouse, $this->site])
            ->assertSee('Site');
    }
}
