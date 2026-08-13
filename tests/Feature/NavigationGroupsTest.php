<?php

namespace Tests\Feature;

use App\Filament\Navigation\DomainNavigationManager;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
use App\Support\NavigationTree;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Where things sit in the sidebar, asserted against the navigation Filament
 * actually builds rather than the $navigationGroup properties — a property says
 * what a resource asked for, this says what the person looking at the panel gets.
 */
class NavigationGroupsTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    /**
     * @param  string  $type  Which kind of tenant to look at. Personal accounts
     *                        get a group business ones do not, so the two views
     *                        are genuinely different and both need asserting.
     * @return array<string, array<int, string>> group label => item labels
     */
    private function navigation(string $type = Company::TYPE_BUSINESS): array
    {
        $this->seed(PermissionSeeder::class);

        $company = Company::factory()->create(['type' => $type]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($company->getKey());
        (new RoleSeeder)->run();

        // A super admin, so nothing is missing merely for want of a permission.
        $user = User::factory()->create(['is_super_admin' => true, 'status' => 1]);
        $company->users()->attach($user);

        $this->actingAs($user);
        $this->setCurrentTenant($company);

        $navigation = [];

        // getNavigation(), not buildNavigation(): the latter answers only when
        // a custom navigation builder closure is registered, and returns an empty
        // array otherwise — a test asserting against it would pass on nothing.
        //
        // Read unfiltered, because what this file is about is which groups the panel *offers* a
        // company type. The sidebar now shows one domain at a time (see NavigationDomains), so
        // getNavigation() on its own answers "what is in the domain this request is in" — which
        // for a test with no panel route is Home, and would reduce every assertion here to three
        // items. The subject did not change; the way to see all of it did.
        $groups = DomainNavigationManager::withoutFiltering(
            fn (): array => Filament::getPanel('admin')->getNavigation(),
        );

        // Folded back to the groups the classes declare. The columns now show those groups split into
        // branches — Employee as Employees / Payroll / Leave / … — see NavigationTree. What this file
        // is about is which groups the application organises its screens into and which screen belongs
        // to which, and that is unchanged by how a column chooses to show them; every assertion below
        // and the reasoning attached to it still holds at this level.
        //
        // Read from the rendered navigation rather than from the classes, because half of these
        // assertions are about what a *particular* company and role are offered, and that only comes
        // out of navigation Filament has actually filtered.
        foreach ($groups as $group) {
            $label = NavigationTree::declaredFor($group->getLabel() ?? '');

            $navigation[$label] = [
                ...$navigation[$label] ?? [],
                ...collect($group->getItems())->map(fn ($item): string => $item->getLabel())->all(),
            ];
        }

        return $navigation;
    }

    public function test_fiscal_years_and_salary_slabs_live_under_settings(): void
    {
        $navigation = $this->navigation();

        $this->assertArrayNotHasKey(
            'Salary Slab & Fiscal Year',
            $navigation,
            'the group named after its own two resources is gone'
        );

        $this->assertContains('Fiscal Years', $navigation['Settings'] ?? []);
        $this->assertContains('Salary Slabs', $navigation['Settings'] ?? []);
    }

    public function test_invoicing_and_inventory_are_one_group(): void
    {
        $navigation = $this->navigation();

        $this->assertArrayNotHasKey('Invoicing', $navigation);
        $this->assertArrayNotHasKey('Inventory', $navigation);

        $merged = $navigation['Invoicing & Inventory'] ?? [];

        // Both sides of the merge, and nothing dropped on the way. Invoice Lines
        // is absent by its own choice ($shouldRegisterNavigation = false) — it is
        // reached through an invoice, not the sidebar.
        foreach (['Invoices', 'Contacts', 'Products', 'Stock Movements'] as $item) {
            $this->assertContains($item, $merged);
        }
    }

    public function test_audit_and_taxes_are_one_group(): void
    {
        $navigation = $this->navigation();

        $this->assertArrayNotHasKey('Audit', $navigation);
        $this->assertArrayNotHasKey('Taxes', $navigation);

        $merged = $navigation['Audit & Taxes'] ?? [];

        $this->assertContains('Activity Logs', $merged);
        $this->assertContains('Annual Taxes', $merged);
    }

    public function test_users_sit_with_roles(): void
    {
        $navigation = $this->navigation();

        // A group holding Users alone said nothing its own label did not.
        $this->assertArrayNotHasKey('User', $navigation);

        foreach (['Users', 'Roles'] as $item) {
            $this->assertContains($item, $navigation['Access Control'] ?? []);
        }
    }

    /**
     * Permissions is not here. The set of permissions is what the *code* checks — inventing
     * one does nothing and deleting one breaks every company at once — so it is
     * administered on the platform panel, while which of a company's roles hold which
     * permissions stays with that company.
     */
    public function test_permissions_are_not_offered_to_a_company(): void
    {
        $this->assertNotContains('Permissions', $this->navigation()['Access Control'] ?? []);
    }

    /** The platform panel has its own, much shorter, set of groups. */
    public function test_the_platform_panel_carries_only_installation_level_things(): void
    {
        $this->seed(PermissionSeeder::class);

        $this->actingAs(User::factory()->create(['is_super_admin' => true, 'status' => 1]));

        Filament::setCurrentPanel(Filament::getPanel('platform'));
        Filament::bootCurrentPanel();

        $navigation = [];

        foreach (Filament::getPanel('platform')->getNavigation() as $group) {
            $navigation[$group->getLabel() ?? ''] = collect($group->getItems())
                ->map(fn ($item): string => $item->getLabel())
                ->all();
        }

        $merged = array_merge(...array_values($navigation));

        foreach (['Companies', 'Users', 'Permissions', 'Activity'] as $item) {
            $this->assertContains($item, $merged, "the platform panel offers {$item}");
        }

        // Nothing that needs a company's database, which this panel has not connected.
        foreach (['Payslips', 'Invoices', 'Fiscal Years', 'Email Templates', 'Company Settings'] as $item) {
            $this->assertNotContains($item, $merged, "{$item} needs a tenant connection");
        }
    }

    public function test_payslips_sit_with_the_other_employee_records(): void
    {
        $navigation = $this->navigation();

        $this->assertArrayNotHasKey('Payslip', $navigation);
        $this->assertContains('Payslips', $navigation['Employee'] ?? []);
    }

    public function test_mpr_sits_with_the_other_employee_records(): void
    {
        $navigation = $this->navigation();

        $this->assertArrayNotHasKey('MPR', $navigation);
        $this->assertContains('MPR', $navigation['Employee'] ?? []);
    }

    public function test_gnucash_import_sits_with_the_other_import(): void
    {
        $navigation = $this->navigation();

        // It spent a while under Reports, which is where the accounting odds and
        // ends had collected. It reads a file and writes a ledger; the page it
        // belongs next to is Import from CSV.
        $settings = $navigation['Settings'] ?? [];

        $this->assertContains('GnuCash Import', $settings);
        $this->assertContains('Import from CSV', $settings);
    }

    public function test_table_views_is_nowhere_in_the_sidebar(): void
    {
        // The resource was deleted outright; saved views are made from the bar on
        // each table instead. This is the regression guard for it coming back by
        // way of a new resource nobody meant to add to the menu.
        $everything = collect($this->navigation())->flatten()->all();

        $this->assertNotContains('Table Views', $everything);
    }

    /**
     * The layout itself, in one place.
     *
     * Updating this when a group is added or renamed is the intended cost: it is
     * what makes a change to the sidebar deliberate and reviewable, instead of
     * something noticed later by whoever goes looking for a screen that moved.
     */
    public function test_the_sidebar_is_made_of_exactly_these_groups(): void
    {
        $labels = array_values(array_filter(array_keys($this->navigation())));

        sort($labels);

        // No Reports: the fourteen report pages are reached through the single
        // top-level Reports link instead, which ReportsHubTest covers.
        //
        // No Personal either, and that is the assertion rather than an omission:
        // this runs against a business, and the individual tax brackets have no
        // business being offered there. See the personal case below.
        //
        // `Sales` is CRM's, added deliberately rather than folded into
        // "Invoicing & Inventory". The two answer different questions: Invoicing is
        // what has been sold and what is owed for it, Sales is who has not bought yet.
        // Putting leads beside invoices would also make the group appear for a company
        // that licensed CRM without Invoicing, which docs/crms-plan.md §1 requires to
        // be possible. It holds leads and their sources now, and the pipeline, deals
        // and quotes of later phases.
        //
        // Leave went into the existing `Employee` group rather than getting one of its
        // own, beside payslips and expense claims — an employee looking for their leave
        // balance is looking where they look for their payslip.
        // `Hiring` and `Performance` are their own groups rather than more of `Employee`,
        // and for the same reason `Sales` is not part of Invoicing: they answer different
        // questions about different people. Everything under `Employee` is about somebody
        // the company employs — their payslip, their leave, their attendance, their kit.
        // Hiring is about people it does not employ, and might not. Performance is a
        // separate conversation with its own cycle, and folding it in would put appraisal
        // ratings next to salary settings, which is the exact adjacency §4.5 spends its
        // length arguing against.
        // `Support` is its own group rather than more of `Sales`, on the same reasoning that
        // separates Sales from Invoicing: they are about different moments with the same people.
        // Sales is winning the work; Support is what happens after it is delivered, and the
        // people doing the two are usually not the same. Quotes and campaigns DO sit under
        // Sales, because both are things you send while trying to win something.
        $this->assertSame([
            'Access Control',
            'Accounting',
            'Audit & Taxes',
            'Employee',
            'Hiring',
            'Invoicing & Inventory',
            'Performance',
            'Sales',
            'Settings',
            'Support',
        ], $labels);
    }

    /**
     * Two tests rather than one, because the panel is booted once per test and
     * asking it about a second tenant mid-test gets the first one's answer.
     * The pair matters more than either half: offering the individual tax
     * schedules inside a business would invite somebody to read a company's
     * income as one person's taxable income, and withholding them from a
     * personal account would leave it with no screen of its own.
     */
    public function test_a_personal_account_gets_the_personal_group(): void
    {
        $this->assertContains(
            'Personal',
            array_keys($this->navigation(Company::TYPE_PERSONAL)),
            'A personal account has no Personal group, so it has no screen of its own.',
        );
    }

    public function test_a_business_is_not_offered_the_personal_group(): void
    {
        $this->assertNotContains(
            'Personal',
            array_keys($this->navigation(Company::TYPE_BUSINESS)),
            'A business is being offered the individual tax schedules.',
        );
    }

    /**
     * Guards the guard. Every assertion above reads the same helper, so a change
     * that made it return nothing — a panel that fails to boot, a tenant that
     * never gets set — would turn the whole file green and meaningless.
     */
    public function test_the_navigation_helper_actually_finds_the_navigation(): void
    {
        $navigation = $this->navigation();

        $this->assertGreaterThanOrEqual(7, count($navigation));
        $this->assertGreaterThanOrEqual(30, collect($navigation)->flatten()->count());
        $this->assertContains('Dashboard', collect($navigation)->flatten()->all());
    }
}
