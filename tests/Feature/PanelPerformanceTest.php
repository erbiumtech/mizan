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
use Illuminate\Support\Str;
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
    /*
     * **Raised on 2026-08-22 for `construction_qhse`'s three navigation badges**, and the reasoning is the point rather
     * than the number.
     *
     * A badge is one indexed count per page and the rule for earning one is that the failure it warns about is *silent* —
     * that not looking today costs something nobody can otherwise see. This module has exactly three that qualify: a
     * hold point that passed and was never released (work standing still for want of a signature), a reportable incident
     * nobody told the authority about (a statutory clock no other screen watches), and **a permit past its window and
     * still open**, which §17.5 calls "the failure mode that kills people".
     *
     * Two others were *removed* in Phase 10c when they pushed the reports hub over — a critical-NCR count and an
     * overdue-action count, both of which are the first row of a screen somebody opens daily and therefore loud rather
     * than silent. That is the same discipline as this increase, not the opposite of it: **the rule decides what exists
     * and the budget accommodates what the rule allows.** What must never happen is the reverse — trimming a justified
     * count to fit, or raising the ceiling for one that was never justified.
     *
     * **The dashboard's cold budget moved 32 to 33 on 2026-08-25 for Phase 7's layout read**, which is the
     * sanctioned kind rather than the kind this file has fixed instead of budgeted for. **The warm budget did not
     * move**: the same query runs on a warm request and it measures 6 against a budget of 8, so it lands inside
     * headroom that already existed — and a ceiling raised for a query that already fits is the formality this file
     * keeps warning about.
     *
     * The distinction is worth being precise about, because the two look identical in a diff. What was *fixed* here
     * before was waste: a badge that read a whole table to count it, a badge that eager-loaded a relation it did not
     * use, and — in Phase 5.7 — `DashboardStats::resolve()` running twice to produce one figure. Each of those ran a
     * query a correct implementation does not need. A per-user dashboard layout is not in that class: the page cannot
     * render an arrangement without reading it, so there is no version of this feature with no query in it.
     *
     * What *was* available to reduce, and is reduced: it is **one** query rather than two or twenty. One statement
     * fetches the personal row and the company default together — `where user_id is null or user_id = ?` — rather
     * than asking for mine and then asking for the default when I have none; and the page memoises the answer, so
     * resolving the widgets, listing them for the arranger and rendering them share a single read. The exact count
     * matters, which is why it is measured rather than estimated: 33 cold and 6 warm, with `dashboard_layouts`
     * appearing exactly once in each statement list.
     *
     * Caching it would have saved that one query and was rejected: `DashboardCache`'s TTL is five minutes, so
     * somebody would drag a card and watch it spring back. A stale *figure* is a trade this application makes
     * deliberately; a stale *arrangement* is a bug report.
     */
    /*
     * **Every budget moved by one on 2026-08-26 for Phase 6.4's custom reports, and the reason it hits all three
     * pages is worth stating rather than absorbing.** A report somebody assembled is a row of
     * `report_definitions`, so the hub cannot list one without reading them — the same "cannot render it without
     * reading it" as Phase 7's layout. What makes this land on the *dashboard* and on the *employees* index as
     * well is `filament/partials/domain-rail.blade.php`: the rail's Reports flyout renders the categories and
     * their counts on every page in the panel, so the count of a section is part of every page's shell.
     *
     * Two alternatives were considered and both were worse than one query. Reading the definitions only in the
     * reports domain would make the flyout say nine categories on the dashboard and ten on a reports page, which
     * is a count that changes as you navigate — a bug report. Leaving custom reports out of the counts entirely
     * would mean the flyout's "All reports" number disagrees with the list the hub draws.
     *
     * What is available to reduce is reduced: the read is memoised per request in
     * `ReportDefinition::visible()`, so the section chips, the counts, the rows, the column and `select()`'s own
     * check share one statement — `report_definitions` appears exactly once in each statement list. The
     * availability filter over it is deliberately *not* memoised, because which subjects a reader may open can
     * change inside the request that changes it.
     */
    private const BUDGET = [
        'dashboard' => ['cold' => 34, 'warm' => 9],
        'employees' => ['cold' => 31, 'warm' => 11],
        'reports' => ['cold' => 30, 'warm' => 7],
    ];

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * **A fixture of fixed length, because one of the budgets below is measured in bytes.**
         *
         * `CompanyFactory` names a company from faker and derives its slug from that name, and **the slug is
         * in every panel URL on the page** — the sidebar and the domain rail together are around a hundred
         * links. So "Kub Inc" and "Bauch, Lehner and Ziemann" differ by roughly 1.8 KB of rendered HTML, which
         * is more headroom than the 400 KB employees ceiling has: the same tree measured 397.5 KB and 398.5 KB
         * on consecutive runs, and a full suite would fail this assertion perhaps one run in three. Found on
         * 2026-08-28 while checking whether a feature had added bytes to the page; it had not, and the
         * measurement had never been able to tell.
         *
         * The slug stays *unique* — `Str::random(8)` — so each test still gets its own tenant database file.
         * What is fixed is its length, and the user's name for the same reason: the account menu renders it.
         *
         * This is the second time this test's measurement rather than its subject has been the fault; the
         * `modules()->flush()` below is the first. A byte budget over a fixture that varies in size is not a
         * ratchet, it is a coin toss with a threshold.
         */
        $slug = 'perf-'.Str::lower(Str::random(8));

        $this->company = Company::factory()->create([
            'name' => 'Performance Test Company',
            'slug' => $slug,
            'database' => database_path("tenants/{$slug}.sqlite"),
        ]);
        $this->seed(PermissionSeeder::class);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->getKey());
        (new RoleSeeder)->run();

        // An Administrator rather than a super admin: a super admin short-circuits every gate in
        // Gate::before, which changes what the sidebar evaluates and would measure a path almost
        // nobody uses.
        $user = User::factory()->create(['name' => 'Performance Tester', 'status' => 1]);
        $this->company->users()->attach($user->getKey());
        $user->assignRole('Administrator');

        $this->actingAs($user);
        $this->setCurrentTenant($this->company);

        /*
         * **Flushed, because everything measured here is a function of what is licensed.**
         *
         * `modules()` memoises the resolved licence set, and a test that ran before this one may have left another
         * company's answer in it — so the sidebar renders a different number of groups and the page-size measurement
         * moves. Found in Phase 10b: the Employees size budget passed in isolation and failed in a combined run, which
         * is the *measurement* being unstable rather than the page.
         */
        modules()->flush();
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
     * **All three ceilings moved on 2026-08-22 — 260/360/290 to 300/400/330 — and the reason is worth distinguishing
     * from the one that must never move them.** The rail carries every domain's tree on every page, so licensing a
     * module with a new navigation group makes every page in the panel bigger: `construction_qhse` and its
     * `Quality & Safety` group put the Employees index 0.7 KB over, and its second register put the dashboard 0.9 KB
     * over. That is the budget measuring exactly what it is for — bytes on the wire — and it is **not** waste, unlike
     * the query-count failures in this same file, which were a badge reading a whole table and a badge eager-loading a
     * relation. Those were fixed rather than budgeted for.
     *
     * The distinction to hold: **raise a ceiling for markup a new screen legitimately adds; never raise one to make a
     * page that got heavier for no reason pass.** The headroom is deliberately more than the next register needs,
     * because §17 has four more to land and bumping by a kilobyte each time turns a ratchet into a formality — the
     * numbers below are ~15% above what a fully licensed construction company renders today.
     *
     * **The reports ceiling moved on 2026-08-23 — 330 to 360 — for the same kind of reason, and it exposed that the
     * headroom above was already spent.** Every report in `docs/reports-expansion-plan.md` is a card in the hub and a
     * row in its sidebar column, so the hub is the one page in the panel that grows with each phase. The asset
     * register (Phase 2.5) put it 3 KB over; measured without it the page was already 328–329 KB against 330, so the
     * ~15% claimed above had become ~0.5% while nothing failed.
     *
     * Two things follow, and the second is the one to act on. A card costs ~4.5 KB, so 360 buys roughly six more
     * reports rather than one — Phase 2 has three left and Phase 3 eleven. **At that rate the remaining plan needs
     * ~60 KB the ceiling does not have, so the hub's card markup is what should give way next, not this number.**
     * Raising it again without looking at the card would be the formality this comment warns about.
     *
     * **The hub's card markup did give way, on 2026-08-24, rather than the number.** Phase 4 put the page at
     * 366 KB against 360; the 51 rows were 70.3 KB of which 30.4 KB was inline heroicons — 8% of the page in
     * icons nobody navigates by. Nine `<symbol>`s and fifty-one `<use>`s took that to 6.2 KB and the page to
     * 348.5 KB. Which is what this comment asked for, and the ceiling did not move.
     *
     * **The dashboard ceiling moved on 2026-08-25 — 300 to 350 — and this is the sanctioned case, not the
     * formality.** `docs/reports-expansion-plan.md` Phase 5 replaces the dashboard with one carrying five
     * groups of widgets; eight of them landed and put the page at 304.7 KB. That is markup a new screen
     * legitimately adds, which is the distinction this comment draws — and unlike the hub there is nothing
     * per-item to reduce: a widget's cost *is* its Livewire component, and the only way to render fewer bytes
     * is to render fewer widgets, which is to not build the phase.
     *
     * Measured 2026-08-25: 304.7 KB with 17 widgets registered, of which the Livewire snapshots are 23.7 KB —
     * about 1.1 KB per component, ~1.4 KB all-in per widget. Phase 5's remaining groups (people, inventory)
     * are roughly six more, so ~313 KB, and 350 leaves headroom for about twenty-six widgets beyond the plan.
     * That is deliberately more than the plan needs, for the reason above: bumping by a kilobyte a group turns
     * a ratchet into a formality.
     *
     * What is *not* in that figure and is worth knowing: 88.6 KB of the dashboard is inline SVG, most of it
     * the domain rail's flyouts, which this comment has accepted as a measured cost since it was written. If
     * a future phase needs the ceiling back, the rail is where the bytes are — not the widgets.
     *
     * **The card gave way a second time, on 2026-08-26, and the thing that gave was whitespace.** The plan
     * finished at fifty-two reports and the hub reached 370.8 KB against 360 — the arithmetic this comment
     * predicted, arriving on schedule. Measured before touching anything: an explorer row was **947 bytes**,
     * of which some 450 was the indentation of a block laid out over twenty-four lines, and the sidebar
     * column's link was 316 bytes for a single anchor. Fifty-two of each, twice over on the same page.
     *
     * Writing those two loop bodies as one line each — with everything the attributes used to say moved into
     * a comment above the loop, where it reads better anyway — took the row to **481 bytes**, the link to
     * **125**, and the page to **335.9 KB**. Thirty-five kilobytes, no behaviour changed, and the ceiling
     * did not move. Which is the same answer as 2026-08-24's: what a page repeats fifty times is where its
     * bytes are, and formatting the browser discards is the cheapest kilobyte in the building.
     */
    public function test_the_rendered_pages_stay_within_their_size_budget(): void
    {
        $pages = [
            'dashboard' => [Filament::getPanel('admin')->getUrl($this->company), 350],
            'employees' => [EmployeeResource::getUrl('index'), 400],
            'reports' => [Reports::getUrl(), 360],
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
