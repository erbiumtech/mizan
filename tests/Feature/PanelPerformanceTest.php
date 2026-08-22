<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Core\Filament\Pages\Reports;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Filament\Resources\Employees\EmployeeResource;
use App\Modules\Payroll\Filament\Resources\PayComponents\PayComponentResource;
use App\Modules\Payroll\Models\PayComponent;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * A query budget for the panel's own chrome, cold and warm.
 *
 * A stopwatch would be the obvious thing here and it is the wrong one: timings depend on the machine,
 * on what else it is doing, and on whether the database is warm, so a threshold that holds on CI is
 * meaningless on a laptop. Query counts do not move like that — a page that suddenly runs twenty more
 * of them is a regression whatever the wall clock says.
 *
 * **Cold and warm are both asserted, and the pair is the point.** Nobody loads one page: the figure
 * that describes using this application is the second page within the minute, once
 * `App\Support\NavigationBadge` has the eleven sidebar counts cached. Asserting only the cold path
 * would let the cache silently stop working — every page would still pass a cold budget. Asserting
 * only the warm path would hide a new count being added to the first load of every session.
 *
 * Note what this does not measure. The suite runs single-database on sqlite (phpunit.xml empties
 * TENANT_DATABASE_CONNECTION) against empty tables, so these are not production's counts — there the
 * same page also pays a landlord lookup and a connection switch, and a table with rows in it costs
 * more than one with none. What carries over is the shape, and the shape is what regresses.
 *
 * See docs/page-load-performance-plan.md for what these numbers were before Phases 1-5 and where the
 * difference went.
 */
class PanelPerformanceTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    /**
     * Measured 2026-08-13 — cold 23/23/20, warm 5/6/3 — plus headroom.
     *
     * Tight on purpose. A budget of sixty would pass through every regression this exists to catch:
     * twelve of the cold twenty-three are `count(*)`s for sidebar badges, so anything that doubles the
     * shell's work still lands inside a loose ceiling and nobody hears about it. The warm budgets are
     * the ones to watch — they are small enough that one uncached count shows up immediately.
     *
     * @var array<string, array{cold: int, warm: int}>
     */
    private const BUDGET = [
        'dashboard' => ['cold' => 28, 'warm' => 8],
        'employees' => ['cold' => 30, 'warm' => 10],
        'reports' => ['cold' => 25, 'warm' => 6],
    ];

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->seed(PermissionSeeder::class);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->getKey());
        (new RoleSeeder)->run();

        // An Administrator rather than a super admin: a super admin short-circuits every gate in
        // Gate::before, which changes what the sidebar evaluates and would measure a path almost
        // nobody uses.
        $user = User::factory()->create(['status' => 1]);
        $this->company->users()->attach($user->getKey());
        $user->assignRole('Administrator');

        $this->actingAs($user);
        $this->setCurrentTenant($this->company);
    }

    public function test_the_dashboard_stays_within_its_query_budget(): void
    {
        $this->assertWithinBudget('dashboard', Filament::getPanel('admin')->getUrl($this->company));
    }

    public function test_a_resource_index_stays_within_its_query_budget(): void
    {
        $this->assertWithinBudget('employees', EmployeeResource::getUrl('index'));
    }

    public function test_the_reports_hub_stays_within_its_query_budget(): void
    {
        $this->assertWithinBudget('reports', Reports::getUrl());
    }

    /**
     * The badge counts really are cached, rather than merely being fewer.
     *
     * Asserted as a property of the second request rather than by a number: eleven `count(*)`s on the
     * first page and one on the next is the whole of Phase 2, and a cache that quietly stopped
     * working — a key that includes something per-request, a `scoped` binding turned transient —
     * shows up here and in no other test.
     */
    public function test_the_second_page_within_the_minute_stops_counting(): void
    {
        $url = EmployeeResource::getUrl('index');

        $cold = $this->countsIn($this->queriesFor($url));
        $warm = $this->countsIn($this->queriesFor($url));

        $this->assertGreaterThanOrEqual(10, $cold, 'the sidebar badge counts are not being run at all');

        // One survives, and it is the notification bell: it is not a navigation badge and nothing
        // caches it. See the plan's outstanding items.
        $this->assertLessThanOrEqual(
            2,
            $warm,
            "the badge cache is not holding: {$warm} count(*) statements on a warm request",
        );
    }

    /**
     * A page's query count must not grow with the number of rows on it.
     *
     * **This is the test the rest of this file cannot be.** Every budget above was measured against
     * empty tables, where a query per row multiplies by nothing — so a per-row lookup passes them all.
     * And since Phase 6, `preventLazyLoading` does not catch it either: the fixes that satisfy the
     * guard include `loadMissing` calls inside model methods, which are explicit, legal, and still one
     * query per row when the method is called down a list. This asserts the only property that
     * actually distinguishes eager loading from a polite N+1.
     *
     * Pay components rather than employees: the employees table defers its load, so its first render
     * runs no row query at all and would prove nothing. This table renders its rows eagerly and its
     * posting-account column reaches through a relation, which is exactly the shape at issue.
     *
     * Both renders are warm, deliberately — a cold first render would populate the badge cache and the
     * second would come back *cheaper*, hiding row growth behind cache warming.
     */
    public function test_a_pages_query_count_does_not_grow_with_its_rows(): void
    {
        $url = PayComponentResource::getUrl('index');

        $this->payComponents(2);
        $this->queriesFor($url);              // warm the caches; not measured
        $few = $this->queriesFor($url);

        // Up to ten, which is Filament's default page size — enough to multiply a per-row query by
        // eight, few enough that they are all still on the first page.
        $this->payComponents(8);
        $many = $this->queriesFor($url);

        $growth = count($many) - count($few);

        $this->assertLessThanOrEqual(
            2,
            $growth,
            "the page ran {$growth} more queries for eight more rows, so something on it is per-row:\n\n"
            .implode("\n", array_diff($many, $few)),
        );
    }

    /**
     * The rendered page stays within a size budget.
     *
     * Bytes are the other half of "feels instant" and nothing here was watching them: the domain
     * rail's flyouts carry every domain's tree on every page, which is ~118 KB of the Employees
     * index's markup, and they arrived without a single test noticing. Under SPA mode this is the
     * payload of every navigation, and under hover prefetching it is the payload of every *hover*.
     *
     * Raw rather than compressed, because compression is a deployment concern (Phase 5) and this has
     * to fail on a laptop where nothing is compressed. Measured 2026-08-14: 207 / 301 / 231 KB.
     *
     * **The Employees ceiling moved 360 -> 400 KB on 2026-08-22, and the reason is worth distinguishing from the one
     * that must never move it.** The rail carries every domain's tree on every page, so licensing a module with a new
     * navigation group makes every page in the panel bigger — `construction_qhse` and its `Quality & Safety` group put
     * the Employees index 0.7 KB over. That is the budget measuring what it is for: bytes on the wire. It is *not*
     * waste, unlike the query-count failures in the same file, which were a badge reading a whole table and a badge
     * eager-loading a relation — both fixed rather than budgeted for.
     *
     * The distinction to hold: **raise this ceiling for markup a new screen legitimately adds; never raise it to make a
     * page that got heavier for no reason pass.** The headroom is deliberately more than one module needs, because §17
     * has five more registers to land and bumping by a kilobyte six times would turn a ratchet into a formality.
     */
    public function test_the_rendered_pages_stay_within_their_size_budget(): void
    {
        $pages = [
            'dashboard' => [Filament::getPanel('admin')->getUrl($this->company), 260],
            'employees' => [EmployeeResource::getUrl('index'), 400],
            'reports' => [Reports::getUrl(), 290],
        ];

        foreach ($pages as $page => [$url, $ceilingKb]) {
            $kb = strlen($this->get($url)->assertOk()->getContent()) / 1024;

            $this->assertLessThanOrEqual(
                $ceilingKb,
                $kb,
                sprintf('[%s] rendered %.0f KB against a budget of %d KB', $page, $kb, $ceilingKb),
            );
        }
    }

    /**
     * No statement should run more than a handful of times in one request.
     *
     * This is the guard against the specific regression this shell has already been through: a second
     * navigation build, which adds no new *kinds* of query but runs every existing one again (see
     * NavigationSnapshot). A repeat count that jumps is the signature, and it shows up here before
     * anybody notices the page got slower.
     */
    public function test_no_single_statement_is_repeated_excessively(): void
    {
        $queries = $this->queriesFor(EmployeeResource::getUrl('index'));

        $counts = array_count_values($queries);
        arsort($counts);

        $worst = array_key_first($counts);

        // 4 against a measured worst case of 2 (`select * from "employees"`, which runs twice on this
        // page and is itself worth looking at — Phase 2.3 of the plan).
        $this->assertLessThanOrEqual(
            4,
            $counts[$worst],
            "one statement ran {$counts[$worst]} times:\n{$worst}",
        );
    }

    private function assertWithinBudget(string $page, string $url): void
    {
        foreach (['cold', 'warm'] as $state) {
            $queries = $this->queriesFor($url);
            $count = count($queries);
            $budget = self::BUDGET[$page][$state];

            $this->assertLessThanOrEqual(
                $budget,
                $count,
                "[{$page}, {$state}] ran {$count} queries against a budget of {$budget}:\n\n"
                .implode("\n", array_map(
                    fn (string $sql, int $i): string => ($i + 1).". {$sql}",
                    $queries,
                    array_keys($queries),
                )),
            );
        }
    }

    /**
     * A handful of pay components, each with its own posting account.
     *
     * Distinct accounts on purpose: a shared one would be loaded once and the per-row lookup this is
     * built to detect would look like eager loading.
     */
    private function payComponents(int $count): void
    {
        $existing = PayComponent::count();

        for ($i = $existing + 1; $i <= $existing + $count; $i++) {
            PayComponent::create([
                'code' => "perf_{$i}",
                'label' => "Perf {$i}",
                'kind' => PayComponent::KIND_EARNING,
                'account_id' => Account::create([
                    'code' => sprintf('9%03d', $i),
                    'name' => "Perf account {$i}",
                    'type' => 'expense',
                ])->id,
            ]);
        }
    }

    /** @param  array<int, string>  $queries */
    private function countsIn(array $queries): int
    {
        return count(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'count(*)')));
    }

    /**
     * The statements one request runs.
     *
     * A fresh listener per call, and they accumulate — Laravel has no way to remove one — so each call
     * must collect into its own array and read only that. Listening starts here rather than in setUp
     * so the seeding above, which is hundreds of permission inserts, is not counted as page cost.
     *
     * @return array<int, string>
     */
    private function queriesFor(string $url): array
    {
        $queries = [];

        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->get($url)->assertOk();

        return $queries;
    }
}
