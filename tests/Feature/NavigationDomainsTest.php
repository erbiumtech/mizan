<?php

namespace Tests\Feature;

use App\Filament\Navigation\DomainNavigationManager;
use App\Modules\Accounting\Filament\Pages\BalanceSheet;
use App\Modules\Core\Filament\Pages\Reports;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;
use UnitEnum;

/**
 * The two-level shell: a rail of domains, and a column showing one of them.
 *
 * The reason this file is as insistent as it is: **filtering navigation makes anything unmapped
 * unreachable.** A group that belongs to no domain appears in no column, and the screens in it are
 * then reachable only by typing a URL or by remembering they exist and using ⌘K. Nothing throws and
 * no page 404s — the entries are simply not drawn — which is the kind of breakage that ships.
 *
 * So the first two tests assert coverage in both directions and are the point of the file. The rest
 * assert that the column narrows and that the rail follows the screen you are actually on.
 */
class NavigationDomainsTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->seed(PermissionSeeder::class);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->getKey());
        (new RoleSeeder)->run();

        // A super admin, so nothing is absent merely for want of a permission.
        $user = User::factory()->create(['is_super_admin' => true, 'status' => 1]);
        $this->company->users()->attach($user->getKey());
        $user->assignRole('Administrator');

        $this->actingAs($user);
        $this->setCurrentTenant($this->company);
    }

    // ------------------------------------------------------------------ coverage

    /**
     * Every group label the panel declares is claimed by exactly one domain, and every claimed
     * label is one the panel declares.
     *
     * Read from the registered classes rather than from the visible navigation, deliberately: a
     * group whose module is unlicensed for this company is absent from the sidebar but is still a
     * group this application has, and it needs a domain before the company that *does* licence it
     * goes looking. Reading the classes also makes the reverse assertion meaningful — it catches a
     * mapping left behind by a rename, which a visibility-based reading could not tell apart from a
     * module being switched off.
     */
    public function test_every_navigation_group_the_panel_declares_belongs_to_exactly_one_domain(): void
    {
        $declared = $this->declaredGroups();
        $mapped = \App\Support\NavigationDomains::mappedGroups();

        $this->assertSame(
            [],
            array_values(array_diff($declared, $mapped)),
            'these navigation groups belong to no domain, so no column shows them and everything '
            .'in them is reachable only by URL — add them to NavigationDomains',
        );

        $this->assertSame(
            [],
            array_values(array_diff($mapped, $declared)),
            'NavigationDomains claims groups that no resource or page declares — a rename or a '
            .'removal left this mapping behind',
        );

        // Guards the guard: both assertions above pass trivially if the panel declares nothing.
        $this->assertGreaterThanOrEqual(10, count($declared));
    }

    /**
     * The same coverage for pages that declare no group at all.
     *
     * Filament collects those into one unlabelled group, and that group holds items from more than
     * one domain — the Dashboard and the User Manual are Home, the Reports hub is a domain of its
     * own — so they are claimed page by page and a new one is easy to forget.
     */
    public function test_every_page_without_a_group_is_claimed_by_a_domain(): void
    {
        $claimed = \App\Support\NavigationDomains::mappedItems();
        $unclaimed = [];

        foreach (Filament::getPanel('admin')->getPages() as $page) {
            if (filled($page::getNavigationGroup())) {
                continue;
            }

            // A page that registers no navigation item is reached from somewhere else — every
            // report page is hidden in favour of the hub — and needs no claim of its own.
            if (! $page::shouldRegisterNavigation()) {
                continue;
            }

            if (! in_array($page, $claimed, true)) {
                $unclaimed[] = $page;
            }
        }

        $this->assertSame(
            [],
            $unclaimed,
            'these pages sit at the top level of the sidebar and belong to no domain, so the rail '
            .'never shows them',
        );
    }

    // ------------------------------------------------------- the column narrows

    public function test_the_column_shows_the_current_domain_and_not_the_others(): void
    {
        $groups = $this->columnGroupsOn(\App\Modules\Accounting\Filament\Resources\Accounts\AccountResource::getUrl('index'));

        // Branch labels rather than "Accounting": NavigationTree splits that group, and the column
        // shows what it was split into. Two branches from two different declared groups, both of
        // which Finance owns.
        $this->assertContains('Ledger', $groups);

        // Not split, and deliberately so — see NavigationTree. Finance owns it whole.
        $this->assertContains('Invoicing & Inventory', $groups);

        // Branches belonging to the other domains, including two that are easy to confuse with
        // Finance's: People has a Payroll branch and Admin has Payroll setup.
        $this->assertNotContains('Payroll', $groups);
        $this->assertNotContains('Payroll setup', $groups);
        $this->assertNotContains('Employees', $groups);
        $this->assertNotContains('Company', $groups);
    }

    public function test_the_whole_sidebar_is_still_reachable_one_domain_at_a_time(): void
    {
        $seen = [];

        foreach (\App\Support\NavigationDomains::keys() as $domain) {
            $groups = \App\Support\NavigationDomains::filter($this->fullNavigation(), $domain);

            foreach ($groups as $group) {
                if (filled($label = $group->getLabel())) {
                    $seen[] = $label;
                }
            }
        }

        // Every group the panel is currently showing turns up in exactly one domain's column.
        $visible = collect($this->fullNavigation())
            ->map(fn ($group): ?string => $group->getLabel())
            ->filter()
            ->values()
            ->all();

        sort($seen);
        sort($visible);

        $this->assertSame($visible, $seen, 'a visible group is in no column, or in two');
    }

    // ---------------------------------------------------------- the rail follows

    public function test_the_rail_marks_the_domain_being_viewed(): void
    {
        $this->assertSame('Finance', $this->activeDomainOn(
            \App\Modules\Accounting\Filament\Resources\Accounts\AccountResource::getUrl('index'),
        ));

        $this->assertSame('People', $this->activeDomainOn(
            \App\Modules\Employees\Filament\Resources\Employees\EmployeeResource::getUrl('index'),
        ));
    }

    /**
     * The case that route-based resolution exists for.
     *
     * Every report page sets `$shouldRegisterNavigation = false`, so no navigation item ever reports
     * itself active while one is open. Asking the navigation which domain we are in would answer
     * Home, and the rail would say Home while the screen says Balance Sheet.
     */
    public function test_a_report_page_keeps_the_reports_domain_open(): void
    {
        $this->assertSame('Reports', $this->activeDomainOn(BalanceSheet::getUrl()));
        $this->assertSame('Reports', $this->activeDomainOn(Reports::getUrl()));
    }

    public function test_the_dashboard_opens_home(): void
    {
        $this->assertSame('Home', $this->activeDomainOn(Filament::getPanel('admin')->getUrl($this->company)));
    }

    // ------------------------------------------------------------- other panels

    /**
     * The platform panel keeps every group it has.
     *
     * Filament resolves one navigation manager per panel from the same container binding, so the
     * narrowing is global unless it checks. The platform panel's groups appear in no domain, so
     * filtering it would leave a super admin on an empty sidebar — on the one panel that exists to
     * do installation-level work and has no hub page to fall back to.
     */
    public function test_the_platform_panel_is_not_narrowed(): void
    {
        // Made current first, as a request to /platform would. Filament's navigation manager mounts
        // from whichever panel is current, so asking a non-current panel for its navigation answers
        // about the current one — with or without the narrowing this file is about.
        Filament::setCurrentPanel(Filament::getPanel('platform'));
        Filament::bootCurrentPanel();

        $groups = collect(Filament::getPanel('platform')->getNavigation())
            ->map(fn ($group): ?string => $group->getLabel())
            ->filter()
            ->values()
            ->all();

        $this->assertNotEmpty(
            $groups,
            'the platform panel has been caught by the admin panel\'s domain filtering',
        );
    }

    // ----------------------------------------------------------------- helpers

    /** Every group label declared across the panel's resources and pages, licensed or not. */
    private function declaredGroups(): array
    {
        $panel = Filament::getPanel('admin');
        $labels = [];

        foreach ([...$panel->getResources(), ...$panel->getPages()] as $class) {
            $group = $class::getNavigationGroup();

            if ($group instanceof UnitEnum) {
                $group = $group instanceof \Filament\Support\Contracts\HasLabel ? $group->getLabel() : $group->name;
            }

            if (filled($group)) {
                $labels[$group] = true;
            }
        }

        return array_keys($labels);
    }

    /** @return array<\Filament\Navigation\NavigationGroup> */
    private function fullNavigation(): array
    {
        return DomainNavigationManager::withoutFiltering(
            fn (): array => Filament::getPanel('admin')->getNavigation(),
        );
    }

    /**
     * The group labels the column carries while the given URL is open.
     *
     * Asked through a real request because the domain is resolved from the route: a helper that
     * called filter() directly would be asserting the mapping twice and the resolution never.
     *
     * @return array<int, string>
     */
    private function columnGroupsOn(string $url): array
    {
        $html = $this->get($url)->assertOk()->getContent();

        // Out of the rendered sidebar, not by re-resolving the navigation afterwards: the domain
        // comes from the route, and once the response has been returned the current route is the
        // test's own, so a second getNavigation() here would answer for the wrong screen.
        preg_match_all('/fi-sidebar-group-label">\s*([^<]+?)\s*</s', $html, $matches);

        // Decoded: labels with an ampersand — "Invoicing & Inventory", "Attendance & time" — arrive
        // as entities, and comparing against the entity form would be asserting the escaping.
        return array_map(
            fn (string $label): string => html_entity_decode(trim($label), ENT_QUOTES),
            $matches[1] ?? [],
        );
    }

    /**
     * Which rail icon is marked active in the response for a URL.
     *
     * Read out of the rendered HTML rather than by calling current() again, so that what is
     * asserted is what a person would see.
     */
    private function activeDomainOn(string $url): ?string
    {
        $html = $this->get($url)->assertOk()->getContent();

        preg_match(
            '/fi-domain-rail-item fi-active.*?fi-domain-rail-item-label">([^<]+)</s',
            $html,
            $matches,
        );

        return $matches[1] ?? null;
    }
}
