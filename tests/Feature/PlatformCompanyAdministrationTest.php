<?php

namespace Tests\Feature;

use App\Modules\Core\Filament\Platform\Resources\Companies\Pages\ManageCompanyLicences;
use App\Modules\Core\Filament\Platform\Resources\Companies\RelationManagers\MembersRelationManager;
use App\Modules\Core\Filament\Platform\Resources\Companies\RelationManagers\RolesRelationManager;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Administering a company from outside it.
 *
 * Every role read and write here has to name the company's spatie team explicitly. There
 * is no current company on this panel, so the registrar's team is null — and a grant made
 * against a null team lands on a role belonging to nobody, which is the failure that
 * produced five orphan roles earlier this week.
 *
 * The second thing under test is the one a flat list gets wrong: two companies each have
 * an Administrator, they are different roles, and appointing somebody in one must not
 * appoint them in the other.
 */
class PlatformCompanyAdministrationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Company $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);

        $this->company = Company::factory()->create(['name' => 'First AG']);
        $this->other = Company::factory()->create(['name' => 'Second AG']);

        // A factory-made company is an *existing* customer, with every module licensed —
        // which would make the licence tests below pass while granting nothing. Cleared
        // to the state a newly provisioned company is actually in.
        $this->company->companyModules()->delete();
        $this->other->companyModules()->delete();
        modules()->flush();

        $this->actingAs(User::factory()->create(['is_super_admin' => true]));

        Filament::setCurrentPanel(Filament::getPanel('platform'));
        Filament::bootCurrentPanel();
    }

    private function seedRolesFor(Company $company): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($company->getKey());
        (new RoleSeeder)->run();
        $registrar->setPermissionsTeamId(null);
        $registrar->forgetCachedPermissions();
    }

    private function rolesOf(User $user, Company $company): array
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($company->getKey());
        $names = $user->fresh()->roles()->pluck('name')->all();
        $registrar->setPermissionsTeamId(null);

        return $names;
    }

    private function members(): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(MembersRelationManager::class, [
            'ownerRecord' => $this->company,
            'pageClass' => \App\Modules\Core\Filament\Platform\Resources\Companies\Pages\EditCompany::class,
        ]);
    }

    private function roles(): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(RolesRelationManager::class, [
            'ownerRecord' => $this->company,
            'pageClass' => \App\Modules\Core\Filament\Platform\Resources\Companies\Pages\EditCompany::class,
        ]);
    }

    // ---- Roles ---------------------------------------------------------------

    public function test_a_company_with_no_roles_says_so(): void
    {
        // The state that silently breaks everything else: nobody can be given anything
        // to do, and no other screen mentions it.
        $this->roles()->assertSee('This company has no roles');
    }

    public function test_its_roles_can_be_created_from_here(): void
    {
        $this->roles()->callAction('seedRoles');

        $this->assertSame(5, $this->company->roles()->count());
        $this->assertSame(0, $this->other->roles()->count(), 'and only for this company');
    }

    public function test_creating_them_never_makes_a_role_belonging_to_no_company(): void
    {
        // The seeder reads the company from spatie's registrar, which is null on this
        // panel. Naming it is the whole point of the action.
        $this->roles()->callAction('seedRoles');

        $this->assertSame(0, Role::whereNull('company_id')->count());
    }

    public function test_running_it_again_does_not_duplicate_them(): void
    {
        $this->roles()->callAction('seedRoles');
        $this->roles()->callAction('seedRoles');

        $this->assertSame(5, $this->company->roles()->count());
    }

    public function test_it_lists_only_this_companys_roles(): void
    {
        $this->seedRolesFor($this->company);
        $this->seedRolesFor($this->other);

        $this->roles()
            ->assertCanSeeTableRecords($this->company->roles()->get())
            ->assertCanNotSeeTableRecords($this->other->roles()->get());
    }

    // ---- Members -------------------------------------------------------------

    public function test_a_user_can_be_added_as_a_member(): void
    {
        $user = User::factory()->create();

        $this->members()->callTableAction('attach', data: ['recordId' => $user->getKey()]);

        $this->assertTrue($this->company->users()->whereKey($user->getKey())->exists());
    }

    public function test_a_members_roles_are_set_in_this_companys_team(): void
    {
        $this->seedRolesFor($this->company);
        $this->seedRolesFor($this->other);

        $user = User::factory()->create();
        $this->company->users()->attach($user);
        $this->other->users()->attach($user);

        $this->members()->callTableAction('setRoles', $user, ['roles' => ['Administrator']]);

        $this->assertSame(['Administrator'], $this->rolesOf($user, $this->company));
        $this->assertSame([], $this->rolesOf($user, $this->other), 'and not in the other company');
    }

    public function test_a_member_can_hold_a_role_that_is_not_administrator(): void
    {
        // The question is "what is this person here", and the answer includes Accountant.
        $this->seedRolesFor($this->company);

        $user = User::factory()->create();
        $this->company->users()->attach($user);

        $this->members()->callTableAction('setRoles', $user, ['roles' => ['Accountant', 'Manager']]);

        $this->assertSame(['Accountant', 'Manager'], $this->rolesOf($user, $this->company));
    }

    public function test_roles_can_be_taken_away(): void
    {
        $this->seedRolesFor($this->company);

        $user = User::factory()->create();
        $this->company->users()->attach($user);
        $this->members()->callTableAction('setRoles', $user, ['roles' => ['Administrator']]);

        $this->members()->callTableAction('setRoles', $user, ['roles' => []]);

        $this->assertSame([], $this->rolesOf($user, $this->company));
    }

    public function test_granting_a_role_a_company_does_not_have_is_refused_with_the_reason(): void
    {
        // Rather than "role does not exist", which describes the symptom of an unseeded
        // company and not the cause.
        $user = User::factory()->create();
        $this->company->users()->attach($user);

        $this->members()->callTableAction('setRoles', $user, ['roles' => ['Administrator']]);

        $this->assertSame([], $this->rolesOf($user, $this->company));
    }

    public function test_removing_a_member_takes_their_roles_with_them(): void
    {
        // Otherwise the grant survives the membership and comes back if they are re-added.
        $this->seedRolesFor($this->company);

        $user = User::factory()->create();
        $this->company->users()->attach($user);
        $this->members()->callTableAction('setRoles', $user, ['roles' => ['Administrator']]);

        $this->members()->callTableAction('detach', $user);

        $this->assertFalse($this->company->users()->whereKey($user->getKey())->exists());
        $this->assertSame([], $this->rolesOf($user, $this->company));
    }

    // ---- Licences ------------------------------------------------------------

    private function licences(): \Livewire\Features\SupportTesting\Testable
    {
        // By slug, because Company::getRouteKeyName() is 'slug' — the same binding the
        // browser uses.
        return Livewire::test(ManageCompanyLicences::class, ['record' => $this->company->slug]);
    }

    private function licensed(string $module): bool
    {
        return (bool) $this->company->companyModules()
            ->where('module', $module)
            ->value('licensed');
    }

    public function test_a_licence_can_be_granted(): void
    {
        $this->licences()
            ->set('data.payroll', true)
            ->call('save');

        $this->assertTrue($this->licensed('payroll'));
    }

    public function test_granting_a_module_brings_in_what_it_cannot_run_without(): void
    {
        // Selling Invoicing without Accounting sells something that cannot post.
        $this->licences()
            ->set('data.invoicing', true)
            ->call('save');

        $this->assertTrue($this->licensed('invoicing'));
        $this->assertTrue($this->licensed('accounting'), 'invoicing requires accounting');
    }

    public function test_revoking_a_module_takes_its_dependents_with_it(): void
    {
        // Otherwise the company keeps paying for a module that fails on its first invoice.
        // Note that Invoicing is left switched on in the form: the toggle just moved wins,
        // because a licence coming back after being revoked is the one outcome nobody
        // expects from turning a toggle off.
        $this->licences()->set('data.invoicing', true)->call('save');

        $this->licences()
            ->set('data.invoicing', true)
            ->set('data.accounting', false)
            ->call('save');

        $this->assertFalse($this->licensed('accounting'));
        $this->assertFalse($this->licensed('invoicing'));
    }

    public function test_core_cannot_be_revoked(): void
    {
        // A company without Core cannot be administered at all, so it has no toggle
        // rather than a disabled one that does nothing.
        $this->licences()->set('data.core', false)->call('save');

        $this->assertTrue($this->licensed('core'));
    }

    public function test_a_licence_does_not_decide_what_the_company_switches_on(): void
    {
        // Two flags, two owners. Revoking a licence must leave their own choice alone, so
        // that re-granting it restores what they had rather than resetting it.
        $this->licences()->set('data.projects', true)->call('save');

        $this->company->companyModules()->where('module', 'projects')->update(['enabled' => true]);

        $this->licences()->set('data.projects', false)->call('save');

        $this->assertFalse($this->licensed('projects'));
        $this->assertTrue(
            (bool) $this->company->companyModules()->where('module', 'projects')->value('enabled'),
            'their switch is untouched',
        );
    }

    /**
     * Through the header action, not the method behind it. The action first named the
     * method as a string, which the action does not invoke — so Save looked like it worked
     * and wrote nothing, and every test calling the method directly would have passed.
     */
    public function test_the_save_button_actually_saves(): void
    {
        $this->licences()
            ->set('data.payroll', true)
            ->callAction('save');

        $this->assertTrue($this->licensed('payroll'));
    }

    public function test_licences_are_per_company(): void
    {
        $this->licences()->set('data.payroll', true)->call('save');

        $this->assertSame(0, $this->other->companyModules()->where('licensed', true)->count());
    }
}
