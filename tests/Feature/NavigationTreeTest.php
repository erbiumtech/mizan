<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
use App\Support\NavigationDomains;
use App\Support\NavigationTree;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * The branches the long groups are split into.
 *
 * The failure this file exists for: an item in a split group that no branch claims keeps the group
 * label it declared, and that label is no longer what any column shows — so the item is in no branch,
 * in no column, and reachable only by URL or ⌘K. Nothing errors. Twenty items became six branches
 * here, and the next person to add a twenty-first will not think to check.
 */
class NavigationTreeTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::factory()->create();
        $this->seed(PermissionSeeder::class);

        app(PermissionRegistrar::class)->setPermissionsTeamId($company->getKey());
        (new RoleSeeder)->run();

        $user = User::factory()->create(['is_super_admin' => true, 'status' => 1]);
        $company->users()->attach($user->getKey());
        $user->assignRole('Administrator');

        $this->actingAs($user);
        $this->setCurrentTenant($company);
    }

    /**
     * Every item of a split group is in exactly one branch.
     *
     * Read from the panel rather than from the map, so that a resource added to one of these groups
     * fails here until it is placed. Both directions: an unclaimed item vanishes from the menu, and a
     * claimed item that no longer exists is a rename nobody finished.
     */
    public function test_every_item_of_a_split_group_is_claimed_by_exactly_one_branch(): void
    {
        foreach (NavigationTree::mappedGroups() as $group) {
            $declared = $this->itemLabelsDeclaredIn($group);
            $claimed = NavigationTree::itemsFor($group);

            $this->assertSame(
                [],
                array_values(array_diff($declared, $claimed)),
                "[{$group}] these items are in no branch, so no column shows them — place them in "
                .'NavigationTree',
            );

            $this->assertSame(
                [],
                array_values(array_diff($claimed, $declared)),
                "[{$group}] NavigationTree places items that do not exist — a rename left this behind",
            );

            // Guards the guard: array_diff of two empty lists is also empty.
            $this->assertNotEmpty($declared, "[{$group}] declares nothing, so the map above is dead");
        }
    }

    /** No item in two branches, which would put the same screen in the column twice. */
    public function test_no_item_is_claimed_twice(): void
    {
        foreach (NavigationTree::mappedGroups() as $group) {
            $claimed = NavigationTree::itemsFor($group);

            $this->assertSame(
                array_unique($claimed),
                $claimed,
                "[{$group}] claims an item in more than one branch",
            );
        }
    }

    /**
     * Branch labels are unique across the whole map.
     *
     * Two groups producing the same branch label would be merged by Filament into one group — and if
     * those groups belong to different domains, that one group appears in both columns carrying the
     * other domain's screens. "Payroll" (People) and "Payroll setup" (Admin) are the near miss this
     * guards.
     */
    public function test_branch_labels_are_unique(): void
    {
        $order = NavigationTree::order();

        $this->assertSame(array_unique($order), $order, 'two groups produce the same branch label');
    }

    /** Every branch is claimed by exactly one domain, or its column never appears. */
    public function test_every_branch_belongs_to_exactly_one_domain(): void
    {
        foreach (NavigationTree::order() as $branch) {
            $domains = array_filter(
                NavigationDomains::keys(),
                fn (string $key): bool => in_array($branch, $this->ownedLabels($key), true),
            );

            $this->assertCount(
                1,
                $domains,
                "[{$branch}] belongs to ".count($domains).' domains, and must belong to exactly one',
            );
        }
    }

    /** The order in the map is the order on screen. */
    public function test_the_column_shows_branches_in_the_declared_order(): void
    {
        $html = $this->get(
            \App\Modules\Employees\Filament\Resources\Employees\EmployeeResource::getUrl('index'),
        )->assertOk()->getContent();

        preg_match_all('/fi-sidebar-group-label">\s*([^<]+?)\s*</s', $html, $matches);

        // Decoded, because "Attendance & time" arrives as an HTML entity.
        $shown = array_map(
            fn (string $label): string => html_entity_decode(trim($label), ENT_QUOTES),
            $matches[1],
        );

        // Only the branches: this column also carries Hiring and Performance, which are groups the
        // map leaves alone and which Filament therefore sorts after everything it was given an order
        // for. Where those sit is not what this asserts.
        $branches = array_values(array_filter(
            $shown,
            fn (string $label): bool => in_array($label, NavigationTree::order(), true),
        ));

        $this->assertNotEmpty($branches, 'no branch reached the column at all');

        $this->assertSame(
            array_values(array_intersect(NavigationTree::order(), $branches)),
            $branches,
            'the column orders its branches differently from the map',
        );
    }

    /**
     * The branch holding the page you are on is the one that starts open.
     *
     * Groups are seeded closed, so this is what stops somebody landing on a screen whose entry in the
     * column is hidden inside a shut branch. The seed is what decides it — Filament writes the
     * collapsed labels into localStorage on a first visit — so what is asserted is that the active
     * branch is marked active and is absent from that seeded list.
     *
     * **One request per test method, deliberately.** Filament's active-state closures read
     * `original_request()`, which is a container binding made on the first request of a process; a
     * second GET in the same test inherits the first one's route and every group comes back inactive.
     * That is a property of the test environment rather than of the panel — each real request builds
     * its own container — but it makes a two-request version of this test assert the opposite of the
     * truth.
     */
    public function test_the_branch_holding_the_current_page_starts_open(): void
    {
        $html = $this->get(
            \App\Modules\Employees\Filament\Resources\Employees\EmployeeResource::getUrl('index'),
        )->assertOk()->getContent();

        // The Employees branch is the one Employees belongs to; see NavigationTree.
        $this->assertMatchesRegularExpression(
            '/data-group-label="Employees"[^>]*class="[^"]*fi-active/s',
            $html,
            'the branch holding the current page is not marked active, so nothing opens it',
        );

        // And it is absent from the list Filament seeds localStorage with. Matched as a substring
        // rather than decoded: the seed is @js() output, so the labels arrive with " for their
        // quotes and & for the ampersand in "Attendance & time", and unpicking that would be
        // asserting the encoding rather than the contents.
        preg_match("/JSON\.stringify\(JSON\.parse\('(\[.*?\])'\)\)/s", $html, $seed);

        $this->assertNotEmpty($seed, 'the collapsed-groups seed is no longer written by Filament');

        $this->assertStringNotContainsString('Employees', $seed[1], 'the active branch is seeded closed');
        $this->assertStringContainsString('Payroll', $seed[1], 'the other branches are not seeded closed');
    }

    /**
     * The open branch can still be closed again.
     *
     * NavigationGroup::collapsed() forwards its argument to collapsible(), so seeding the active
     * branch as not-collapsed also declares it not-collapsible — Filament then renders it with no
     * chevron at all, and the one branch a person is provably looking at is the one they cannot fold
     * away. Nothing errors; a control is simply missing.
     */
    public function test_every_branch_keeps_its_collapse_control_including_the_open_one(): void
    {
        $html = $this->get(
            \App\Modules\Employees\Filament\Resources\Employees\EmployeeResource::getUrl('index'),
        )->assertOk()->getContent();

        preg_match_all('/data-group-label="([^"]+)"[^>]*class="([^"]*)"/s', $html, $matches, PREG_SET_ORDER);

        $this->assertNotEmpty($matches, 'no navigation groups rendered');

        foreach ($matches as [, $label, $classes]) {
            $this->assertStringContainsString(
                'fi-collapsible',
                $classes,
                "[{$label}] renders without a collapse control",
            );
        }

        // And the active one is among them, so this is not passing because nothing was active.
        $this->assertMatchesRegularExpression('/data-group-label="Employees"[^>]*fi-active/s', $html);
    }

    /** A group the map says nothing about is passed through untouched. */
    public function test_an_unsplit_group_keeps_its_own_label(): void
    {
        $this->assertSame(['Hiring'], NavigationTree::labelsFor('Hiring'));
        $this->assertFalse(NavigationTree::isMapped('Hiring'));

        $this->assertTrue(NavigationTree::isMapped('Employee'));
        $this->assertNotContains('Employee', NavigationTree::labelsFor('Employee'));
    }

    // ----------------------------------------------------------------- helpers

    /**
     * The item labels the panel registers into a declared group.
     *
     * Read before the tree is applied — the point is to compare what the classes say with what the
     * map claims.
     *
     * @return array<int, string>
     */
    private function itemLabelsDeclaredIn(string $group): array
    {
        $panel = Filament::getPanel('admin');
        $labels = [];

        foreach ([...$panel->getResources(), ...$panel->getPages()] as $class) {
            $declared = $class::getNavigationGroup();

            if ($declared instanceof \UnitEnum) {
                $declared = $declared instanceof \Filament\Support\Contracts\HasLabel
                    ? $declared->getLabel()
                    : $declared->name;
            }

            if ($declared !== $group || ! $class::shouldRegisterNavigation()) {
                continue;
            }

            $labels[] = (string) $class::getNavigationLabel();
        }

        sort($labels);

        return $labels;
    }

    /** Every label a domain owns once its declared groups are expanded through the tree. */
    private function ownedLabels(string $domain): array
    {
        $owned = [];

        foreach (NavigationDomains::definition($domain)['groups'] as $group) {
            foreach (NavigationTree::labelsFor($group) as $label) {
                $owned[] = $label;
            }
        }

        return $owned;
    }
}
