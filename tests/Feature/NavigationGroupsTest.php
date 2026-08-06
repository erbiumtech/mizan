<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
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

    /** @return array<string, array<int, string>> group label => item labels */
    private function navigation(): array
    {
        $this->seed(PermissionSeeder::class);

        $company = Company::factory()->create();
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
        foreach (Filament::getPanel('admin')->getNavigation() as $group) {
            $navigation[$group->getLabel() ?? ''] = collect($group->getItems())
                ->map(fn ($item): string => $item->getLabel())
                ->all();
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
        $this->assertSame([
            'Access Control',
            'Accounting',
            'Audit & Taxes',
            'Employee',
            'Invoicing & Inventory',
            'MPR',
            // A person's own books, not the company's. Its own group rather than
            // a corner of Accounting precisely because the money in it is not
            // the company's money.
            'Personal',
            'Settings',
        ], $labels);
    }

    /**
     * Guards the guard. Every assertion above reads the same helper, so a change
     * that made it return nothing — a panel that fails to boot, a tenant that
     * never gets set — would turn the whole file green and meaningless.
     */
    public function test_the_navigation_helper_actually_finds_the_navigation(): void
    {
        $navigation = $this->navigation();

        $this->assertGreaterThanOrEqual(8, count($navigation));
        $this->assertGreaterThanOrEqual(30, collect($navigation)->flatten()->count());
        $this->assertContains('Dashboard', collect($navigation)->flatten()->all());
    }
}
