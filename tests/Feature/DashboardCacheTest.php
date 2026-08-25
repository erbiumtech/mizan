<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\FiscalYear;
use App\Modules\Core\Models\User;
use App\Modules\Inventory\Filament\Widgets\StockOnHandOverview;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Support\InventoryReports;
use App\Support\Reporting\DashboardCache;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * The dashboard's cache — `docs/reports-expansion-plan.md` Phase 5.8.
 *
 * Three things are asserted, and the third is the one that keeps Phase 5 coherent:
 *
 *  - **the company is in the key**, which is the failure `docs/page-load-performance-plan.md` names — "caching
 *    navigation *across requests* is a cross-tenant leak" — and the reason it is in the key *itself* rather
 *    than left to the store's prefix is that the array store this suite runs on ignores prefixes entirely;
 *  - **the user and the period are in the key too.** The user because several widgets are scoped to what that
 *    person may see, so a shared key is a data leak rather than a wrong number. The period because without it
 *    switching from this month to the financial year shows the month's figures under the year's heading for
 *    five minutes — a caching bug wearing a reporting bug's clothes;
 *  - **the cache is in the widget and never in the service**, so the report reading the same service stays
 *    exact. A report that is quietly five minutes stale is worse than a slow one, because its whole claim is
 *    that the rows add up to the total.
 */
class DashboardCacheTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private const TODAY = '2027-02-19';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::TODAY.' 09:00:00');

        $this->actingAs($this->makeUser('Administrator', 'cache@test.local'));
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

    // ─────────────────── what is in the key ──

    /** The same call twice is computed once. */
    public function test_a_repeated_call_is_computed_once(): void
    {
        $calls = 0;
        $resolve = function () use (&$calls): int {
            $calls++;

            return 42;
        };

        $this->assertSame(42, DashboardCache::remember('w', ['a' => 1], $resolve));
        $this->assertSame(42, DashboardCache::remember('w', ['a' => 1], $resolve));
        $this->assertSame(1, $calls);
    }

    /**
     * The company is in the key.
     *
     * The failure the plan names. Asserted on the array store on purpose: it ignores cache prefixes, so a
     * guard that relied on spatie's `PrefixCacheTask` would pass in production and leak here — which is
     * backwards from what a test should tell you.
     */
    public function test_the_company_is_in_the_key(): void
    {
        DashboardCache::remember('w', ['a' => 1], fn (): string => 'first company');

        $second = Company::factory()->create();
        $second->users()->attach(auth()->id());
        $this->setCurrentTenant($second);

        $this->assertSame(
            'second company',
            DashboardCache::remember('w', ['a' => 1], fn (): string => 'second company'),
        );
    }

    /**
     * And so is the user.
     *
     * Several of these widgets are scoped to what somebody may see, and one to their own work — so a key
     * without the user hands the next caller another person's figures.
     */
    public function test_the_user_is_in_the_key(): void
    {
        DashboardCache::remember('w', ['a' => 1], fn (): string => 'mine');

        $this->actingAs($this->makeUser('Administrator', 'someone-else@test.local'));

        $this->assertSame(
            'theirs',
            DashboardCache::remember('w', ['a' => 1], fn (): string => 'theirs'),
        );
    }

    /**
     * And the parts, which is how the period stays out of the wrong heading.
     *
     * Without this, changing the dashboard's period would show the previous period's figures for five minutes
     * and read as a reporting fault.
     */
    public function test_the_parts_are_in_the_key(): void
    {
        DashboardCache::remember('w', ['period' => 'this_month'], fn (): string => 'february');

        $this->assertSame(
            'the year',
            DashboardCache::remember('w', ['period' => 'year_to_date'], fn (): string => 'the year'),
        );
    }

    /** Two widgets with the same parts do not share a figure. */
    public function test_the_widget_name_is_in_the_key(): void
    {
        DashboardCache::remember('one', ['a' => 1], fn (): string => 'first');

        $this->assertSame('second', DashboardCache::remember('two', ['a' => 1], fn (): string => 'second'));
    }

    /**
     * The order the caller writes the parts in does not matter.
     *
     * A caller writing the same dependencies in a different order should not halve the hit rate, so the parts
     * are sorted before hashing.
     */
    public function test_the_order_of_the_parts_does_not_matter(): void
    {
        $calls = 0;
        $resolve = function () use (&$calls): int {
            $calls++;

            return 7;
        };

        DashboardCache::remember('w', ['period' => 'x', 'days' => 90], $resolve);
        DashboardCache::remember('w', ['days' => 90, 'period' => 'x'], $resolve);

        $this->assertSame(1, $calls);
    }

    /**
     * With nobody signed in, nothing is cached.
     *
     * A console command or a queued job has no company and no user, and caching under "nobody" is the leak
     * this class exists to prevent. `NavigationBadge` takes the same position for the same reason.
     */
    public function test_nothing_is_cached_for_nobody(): void
    {
        auth()->logout();

        $calls = 0;
        $resolve = function () use (&$calls): int {
            $calls++;

            return 1;
        };

        DashboardCache::remember('w', ['a' => 1], $resolve);
        DashboardCache::remember('w', ['a' => 1], $resolve);

        $this->assertSame(2, $calls, 'a figure was cached with nobody to key it on');
    }

    /** Five minutes, which is the plan's figure. */
    public function test_the_ttl_is_five_minutes(): void
    {
        $this->assertSame(300, DashboardCache::TTL_SECONDS);
    }

    /** And a cached figure can be forgotten, keyed the same way. */
    public function test_a_figure_can_be_forgotten(): void
    {
        $calls = 0;
        $resolve = function () use (&$calls): int {
            $calls++;

            return 3;
        };

        DashboardCache::remember('w', ['a' => 1], $resolve);
        DashboardCache::forget('w', ['a' => 1]);
        DashboardCache::remember('w', ['a' => 1], $resolve);

        $this->assertSame(2, $calls);
    }

    // ─────────────────── the widget is cached; the service is not ──

    /**
     * **The service stays exact while the widget goes stale, which is the rule this phase turns on.**
     *
     * If the cache had gone into `InventoryReports::summary()`, the Stock on Hand *report* would read a
     * five-minute-old figure too — and a report whose claim is that its rows add up to its total cannot be
     * quietly behind the ledger it reconciles against. So the service is called fresh here and returns the new
     * figure, while the widget still shows the old one.
     */
    public function test_the_widget_caches_and_the_service_does_not(): void
    {
        $product = $this->product('WIDGET', 5);
        app(InventoryService::class)->purchase($product, 10, 100, '2027-02-01');

        // Warm the widget's cache at 1,000.
        $before = Livewire::test(StockOnHandOverview::class, ['periodTo' => self::TODAY])
            ->assertSuccessful()
            ->assertSee('1,000');

        app(InventoryService::class)->purchase($product, 10, 100, '2027-02-05');

        // The service sees the new stock immediately.
        $this->assertSame(2_000.0, app(InventoryReports::class)->summary(self::TODAY)['value']);

        // The widget does not, for up to five minutes — deliberately.
        Livewire::test(StockOnHandOverview::class, ['periodTo' => self::TODAY])
            ->assertSuccessful()
            ->assertSee('1,000')
            ->assertDontSee('2,000');

        $this->assertNotNull($before);
    }

    /**
     * A different period is a different key, so changing it shows the new figures at once.
     *
     * The other half of putting the period in the key: staleness must never outlive the question.
     */
    public function test_changing_the_period_is_not_stale(): void
    {
        $product = $this->product('WIDGET', 5);
        app(InventoryService::class)->purchase($product, 10, 100, '2027-02-10');

        // As at 1 February there is nothing yet; as at today there is 1,000. Both read in one test, so a
        // shared key would show the first figure twice.
        Livewire::test(StockOnHandOverview::class, ['periodTo' => '2027-02-01'])
            ->assertSuccessful()
            ->assertDontSee('1,000');

        Livewire::test(StockOnHandOverview::class, ['periodTo' => self::TODAY])
            ->assertSuccessful()
            ->assertSee('1,000');
    }

    /**
     * The cached widget runs no queries the second time.
     *
     * Which is the point of the phase: the plan's own framing is "the difference between a dashboard and a
     * report that runs fifteen times a day per user".
     */
    public function test_a_cached_widget_runs_no_queries_the_second_time(): void
    {
        $product = $this->product('WIDGET', 5);
        app(InventoryService::class)->purchase($product, 10, 100, '2027-02-01');

        // Warm it.
        app(StockOnHandOverview::class);
        Livewire::test(StockOnHandOverview::class, ['periodTo' => self::TODAY])->assertSuccessful();

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        Livewire::test(StockOnHandOverview::class, ['periodTo' => self::TODAY])->assertSuccessful();

        // Not zero — Livewire and the panel do their own reads — but none of them the valuation's.
        $this->assertSame(
            [],
            array_values(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'stock_movements'))),
            'the valuation ran again despite being cached',
        );
    }

    private function product(string $sku, float $reorderLevel): Product
    {
        return Product::create([
            'sku' => $sku,
            'name' => 'Product '.$sku,
            'reorder_level' => $reorderLevel,
            'is_active' => true,
        ]);
    }
}
