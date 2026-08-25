<?php

namespace Tests\Feature;

use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\Inventory\Filament\Widgets\StockOnHandOverview;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Support\InventoryReports;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The inventory widget — `docs/reports-expansion-plan.md` Phase 5.6.
 *
 * The item asks for "the same valuation the report states, from the same service", and the sharp end of that
 * is not the valuation but the **reorder rule**. A reorder level of nought means there is *no* level rather
 * than a level of nought: the column defaults to `0`, so treating it as a threshold flags every product that
 * has merely been sold out, since `0 <= 0`. That was a real bug in the Stock on Hand report, found by its own
 * tests — and a widget re-deriving the flag would have reproduced it. The rule is now extracted and both call
 * it, and the tests below assert the widget and the report agree rather than asserting either against a
 * literal.
 */
class InventoryWidgetTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private const TODAY = '2027-02-19';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::TODAY.' 09:00:00');

        $this->actingAs($this->makeUser('Administrator', 'stock-widget@test.local'));
        $company = $this->setCurrentTenant();
        Gate::before(fn () => true);

        foreach (['inventory', 'accounting'] as $module) {
            CompanyModule::updateOrCreate(
                ['company_id' => $company->getKey(), 'module' => $module],
                ['licensed' => true, 'enabled' => true],
            );
        }
        modules()->flush();

        FiscalYear::firstOrCreate(
            ['name' => '2026-2027'],
            ['start_date' => '2026-07-01', 'end_date' => '2027-06-30', 'is_active' => true],
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ────────────────────────────────────────────────── fixtures ──

    private function product(string $sku, float $reorderLevel = 0, bool $active = true): Product
    {
        return Product::create([
            'sku' => $sku,
            'name' => 'Product '.$sku,
            'reorder_level' => $reorderLevel,
            'is_active' => $active,
        ]);
    }

    /**
     * Stock in or out, through `InventoryService`.
     *
     * Not a hand-built `StockMovement`: `type` is NOT NULL and a purchase also posts to the ledger, so a raw
     * insert is both invalid and unrepresentative of anything the application does.
     */
    private function movement(Product $product, float $quantity, float $unitCost, string $date): void
    {
        $service = app(InventoryService::class);

        if ($quantity > 0) {
            $service->purchase($product, $quantity, $unitCost, $date);

            return;
        }

        $service->sale($product, abs($quantity), $unitCost, $date);
    }

    // ─────────────────── the reorder rule, which is the point ──

    /**
     * A product with no reorder level is not below it, even with nothing on hand.
     *
     * The bug this rule exists to prevent: the column defaults to `0`, and `0 <= 0` flagged every sold-out
     * product. A product nobody set a level for is one nobody wants to be told about.
     */
    public function test_a_product_with_no_level_is_never_below_it(): void
    {
        $product = $this->product('SOLD-OUT', reorderLevel: 0);
        $this->movement($product, 5, 100, '2027-02-01');
        $this->movement($product, -5, 100, '2027-02-05');

        $summary = app(InventoryReports::class)->summary(self::TODAY);

        $this->assertSame(0.0, $summary['value'], 'the fixture is not actually sold out');
        $this->assertSame(0, $summary['below_reorder']);
    }

    /** A product at its level is below it — at, not under, because a level is the point you reorder. */
    public function test_a_product_at_its_level_is_below_it(): void
    {
        $product = $this->product('LOW', reorderLevel: 5);
        $this->movement($product, 5, 100, '2027-02-01');

        $this->assertSame(1, app(InventoryReports::class)->summary(self::TODAY)['below_reorder']);
    }

    /** And one above it is not. */
    public function test_a_product_above_its_level_is_not_below_it(): void
    {
        $product = $this->product('FINE', reorderLevel: 5);
        $this->movement($product, 20, 100, '2027-02-01');

        $this->assertSame(0, app(InventoryReports::class)->summary(self::TODAY)['below_reorder']);
    }

    /**
     * A product with a level and no movements at all is below it.
     *
     * It has no row in `stock_movements`, so a summary built from the valuation alone would never see it —
     * and it is exactly what a reorder flag is for.
     */
    public function test_a_product_with_a_level_and_no_stock_is_below_it(): void
    {
        $this->product('NEVER-STOCKED', reorderLevel: 10);

        $summary = app(InventoryReports::class)->summary(self::TODAY);

        $this->assertSame(1, $summary['products']);
        $this->assertSame(1, $summary['below_reorder']);
    }

    /** The rule is one method, and both callers use it. */
    public function test_the_reorder_rule_is_shared(): void
    {
        $this->assertNull(InventoryReports::reorderLevelFor($this->product('NONE', 0)));
        $this->assertSame(5.0, InventoryReports::reorderLevelFor($this->product('SOME', 5)));
        $this->assertFalse(InventoryReports::isBelowReorder($this->product('NONE-2', 0), 0));
        $this->assertTrue(InventoryReports::isBelowReorder($this->product('SOME-2', 5), 5));
    }

    // ─────────────────── agreement with the report ──

    /**
     * The widget's stock value is the report's stock value.
     *
     * Asserted against the report's own tile rather than a literal, which is Phase 5's rule: the two walk
     * separate loops for performance, so a test is what keeps them saying the same thing.
     */
    public function test_the_stock_value_agrees_with_the_report(): void
    {
        $product = $this->product('WIDGET', reorderLevel: 5);
        $this->movement($product, 10, 250, '2027-02-01');

        $report = app(InventoryReports::class)->stockOnHand(self::TODAY);
        $summary = app(InventoryReports::class)->summary(self::TODAY);

        $this->assertSame($report['tiles'][0]['value'], $summary['value']);
        $this->assertSame(2_500.0, $summary['value'], 'the fixture is not what it looks like');
    }

    /** And so does the count below reorder level. */
    public function test_the_reorder_count_agrees_with_the_report(): void
    {
        $this->movement($this->product('LOW', reorderLevel: 5), 3, 100, '2027-02-01');
        $this->movement($this->product('FINE', reorderLevel: 5), 30, 100, '2027-02-01');
        $this->product('NO-LEVEL', reorderLevel: 0);

        $summary = app(InventoryReports::class)->summary(self::TODAY);
        $note = app(InventoryReports::class)->stockOnHand(self::TODAY)['note'];

        $this->assertSame(1, $summary['below_reorder']);
        $this->assertStringContainsString('1 ', $note);
    }

    /** A deactivated product is in neither, because the report lists active ones. */
    public function test_a_deactivated_product_is_not_counted(): void
    {
        $this->movement($this->product('GONE', reorderLevel: 5, active: false), 3, 100, '2027-02-01');

        $summary = app(InventoryReports::class)->summary(self::TODAY);

        $this->assertSame(0, $summary['products']);
        $this->assertSame(0, $summary['below_reorder']);
    }

    // ─────────────────── the as-at, and staleness ──

    /**
     * Stock on hand is a balance, so the period sets the date.
     *
     * Read at a year end it gives the valuation that stood there, which is the figure that ties to the
     * accounts.
     */
    public function test_the_valuation_reads_as_at_the_period_end(): void
    {
        $product = $this->product('WIDGET', reorderLevel: 5);
        $this->movement($product, 10, 100, '2027-01-10');
        $this->movement($product, 10, 100, '2027-02-10');

        $january = app(InventoryReports::class)->summary('2027-01-31');
        $february = app(InventoryReports::class)->summary(self::TODAY);

        $this->assertSame(1_000.0, $january['value']);
        $this->assertSame(2_000.0, $february['value']);
    }

    /**
     * A product that has never moved counts as stale.
     *
     * The report's own reading, and its reason: treating "no history" as fresh would hide every product
     * somebody set up and forgot.
     */
    public function test_a_product_that_never_moved_is_stale(): void
    {
        $this->product('FORGOTTEN', reorderLevel: 0);

        $this->assertSame(1, app(InventoryReports::class)->summary(self::TODAY)['stale']);
    }

    /** One that moved this month is not. */
    public function test_a_product_that_moved_recently_is_not_stale(): void
    {
        $this->movement($this->product('BUSY'), 10, 100, '2027-02-10');

        $this->assertSame(0, app(InventoryReports::class)->summary(self::TODAY)['stale']);
    }

    // ─────────────────── on the widget ──

    /** The widget renders the three figures. */
    public function test_the_widget_renders_the_three_figures(): void
    {
        $this->movement($this->product('LOW', reorderLevel: 5), 3, 250, '2027-02-01');

        Livewire::test(StockOnHandOverview::class, ['periodTo' => self::TODAY])
            ->assertSuccessful()
            ->assertSee('750')
            ->assertSee('at or under a level somebody set')
            ->assertSee('one active product');
    }

    /** With nothing to say it says so, rather than showing three noughts unexplained. */
    public function test_the_widget_says_when_there_is_nothing_to_report(): void
    {
        Livewire::test(StockOnHandOverview::class, ['periodTo' => self::TODAY])
            ->assertSuccessful()
            ->assertSee('nothing needs reordering')
            ->assertSee('everything has moved recently');
    }

    /** It takes the period, is lazy, and sits in the inventory band. */
    public function test_the_widget_follows_the_dashboard_rules(): void
    {
        $this->assertTrue(property_exists(StockOnHandOverview::class, 'periodTo'));
        $this->assertTrue((new \ReflectionProperty(StockOnHandOverview::class, 'isLazy'))->getValue());
        $this->assertSame(50, (new \ReflectionProperty(StockOnHandOverview::class, 'sort'))->getValue());
    }

    /** And gates on its module. */
    public function test_the_widget_is_gated_on_its_module(): void
    {
        CompanyModule::where('company_id', $this->tenant->getKey())->update(['enabled' => false]);
        modules()->flush();

        $this->assertFalse(StockOnHandOverview::canView());
    }
}
