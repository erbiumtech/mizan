<?php

namespace Tests\Feature;

use App\Modules\Core\Filament\Platform\Resources\Roles\Pages\ListPlatformRoles;
use App\Modules\Core\Filament\Platform\Resources\Roles\PlatformRoleResource;
use App\Modules\Core\Filament\Resources\Roles\RoleResource;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
use Database\Seeders\PermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Roles seen from the installation rather than from one company.
 *
 * Until now they were reachable on this panel only under a company, so the cross-company
 * question — which company is missing a role, and where did this extra one come from — meant
 * opening each company in turn. What the list must not do is undo the reason it was arranged
 * that way: role names repeat across companies, and a flat list of them was reported as
 * looking like provisioning had duplicated everything. Hence the assertions about the company
 * column and ordering, which are the point rather than decoration.
 */
class PlatformRolesTest extends TestCase
{
    use RefreshDatabase;

    private Company $first;

    private Company $second;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);

        $this->first = Company::factory()->create(['name' => 'Alpha AG', 'slug' => 'alpha-ag']);
        $this->second = Company::factory()->create(['name' => 'Beta AG', 'slug' => 'beta-ag']);

        $this->superAdmin = User::factory()->create(['is_super_admin' => true]);

        $this->actingAs($this->superAdmin);
        Filament::setCurrentPanel(Filament::getPanel('platform'));
        Filament::bootCurrentPanel();
    }

    private function role(Company $company, string $name): Role
    {
        return Role::create([
            'name' => $name,
            'guard_name' => 'web',
            config('permission.column_names.team_foreign_key', 'company_id') => $company->getKey(),
        ]);
    }

    /**
     * A role belonging to no company.
     *
     * The team key is passed explicitly: spatie fills it from the registrar's current team
     * when the key is absent, so omitting it does not reliably produce an orphan.
     */
    private function orphanRole(): Role
    {
        $role = Role::create([
            'name' => 'Stray',
            'guard_name' => 'web',
            config('permission.column_names.team_foreign_key', 'company_id') => null,
        ]);

        $this->assertNull(
            $role->getAttribute(config('permission.column_names.team_foreign_key', 'company_id')),
            'the fixture is only meaningful if the role really has no company',
        );

        return $role;
    }

    public function test_it_lists_roles_from_every_company(): void
    {
        $here = $this->role($this->first, 'Administrator');
        $there = $this->role($this->second, 'Administrator');

        Livewire::test(ListPlatformRoles::class)
            ->assertCanSeeTableRecords([$here, $there]);
    }

    /**
     * The same name in two companies has to be distinguishable, or the list recreates the
     * confusion the per-company view exists to avoid.
     */
    public function test_each_row_names_the_company_it_belongs_to(): void
    {
        $this->role($this->first, 'Accountant');
        $this->role($this->second, 'Accountant');

        Livewire::test(ListPlatformRoles::class)
            ->assertSee('Alpha AG')
            ->assertSee('Beta AG');
    }

    public function test_it_is_ordered_by_company_so_a_companys_roles_read_together(): void
    {
        $betaAdmin = $this->role($this->second, 'Administrator');
        $alphaAdmin = $this->role($this->first, 'Administrator');
        $alphaClerk = $this->role($this->first, 'Accountant');

        Livewire::test(ListPlatformRoles::class)
            ->assertCanSeeTableRecords([$alphaAdmin, $alphaClerk, $betaAdmin], inOrder: true);
    }

    public function test_it_can_be_filtered_to_one_company(): void
    {
        $mine = $this->role($this->first, 'Manager');
        $theirs = $this->role($this->second, 'Manager');

        Livewire::test(ListPlatformRoles::class)
            ->filterTable(
                config('permission.column_names.team_foreign_key', 'company_id'),
                $this->first->getKey(),
            )
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs]);
    }

    public function test_searching_finds_a_role_by_its_company_name(): void
    {
        $alpha = $this->role($this->first, 'CEO');
        $beta = $this->role($this->second, 'CEO');

        Livewire::test(ListPlatformRoles::class)
            ->searchTable('Beta')
            ->assertCanSeeTableRecords([$beta])
            ->assertCanNotSeeTableRecords([$alpha]);
    }

    /**
     * A role whose company row has gone is reachable by nobody. The left join keeps it
     * visible here instead of dropping it from the only screen that would show it.
     */
    public function test_a_role_with_no_company_is_still_listed(): void
    {
        $orphan = $this->orphanRole();

        Livewire::test(ListPlatformRoles::class)
            ->assertCanSeeTableRecords([$orphan])
            ->assertSee('no company');
    }

    public function test_the_row_action_opens_the_role_on_its_own_company_panel(): void
    {
        $role = $this->role($this->first, 'Administrator');

        Livewire::test(ListPlatformRoles::class)
            ->assertTableActionHasUrl(
                'openOnCompanyPanel',
                "/admin/alpha-ag/roles/{$role->getKey()}/edit",
                record: $role,
            );
    }

    public function test_a_role_with_no_company_offers_nowhere_to_open(): void
    {
        $orphan = $this->orphanRole();

        Livewire::test(ListPlatformRoles::class)
            ->assertTableActionHidden('openOnCompanyPanel', record: $orphan);
    }

    /**
     * Listing only. Editing a role's permissions stays on the company panel, where RoleForm
     * offers the groups that company has licensed — there is no company in context here, so
     * the same form would offer permissions for modules it cannot reach.
     */
    public function test_it_has_no_create_or_edit_page(): void
    {
        $this->assertSame(['index'], array_keys(PlatformRoleResource::getPages()));
    }

    public function test_only_a_super_admin_can_reach_it(): void
    {
        $this->assertTrue(PlatformRoleResource::canAccess());

        $this->actingAs(User::factory()->create(['is_super_admin' => false]));

        $this->assertFalse(PlatformRoleResource::canAccess());
    }

    public function test_it_is_registered_on_the_platform_panel_and_not_the_company_one(): void
    {
        $this->assertContains(
            PlatformRoleResource::class,
            array_values(Filament::getPanel('platform')->getResources()),
        );

        $this->assertNotContains(
            PlatformRoleResource::class,
            array_values(Filament::getPanel('admin')->getResources()),
            'the unscoped list must not appear on a company panel',
        );
    }

    /** The company panel keeps its own scoped Roles screen: nothing was taken away. */
    public function test_the_company_panel_still_has_its_own_roles_resource(): void
    {
        $this->assertContains(
            RoleResource::class,
            array_values(Filament::getPanel('admin')->getResources()),
        );
    }

    public function test_it_sits_beside_permissions_and_users_in_access_control(): void
    {
        $this->assertSame('Access Control', PlatformRoleResource::getNavigationGroup());
    }
}
