<?php

namespace Tests\Feature;

use App\Modules\Core\Filament\Resources\Roles\Pages\EditRole;
use App\Modules\Core\Filament\Resources\Roles\Schemas\RoleForm;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * A disabled module's permissions leave the Roles form — and, more importantly,
 * are not deleted on the way out.
 *
 * The form collects ids from the groups it renders and calls sync(), so filtering
 * the rendered groups by module means every hidden group's permissions are absent
 * from the sync array. Without the preservation below, saving any role while a
 * module is off would strip that module's permissions from it, and re-enabling
 * the module later would find every role silently unconfigured. That is the most
 * likely way this whole feature destroys customer data, so it is tested first.
 */
class ModulePermissionFilteringTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);

        $this->company = Company::factory()->create();

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->getKey());
        (new RoleSeeder)->run();

        $this->admin = User::factory()->create(['is_super_admin' => true]);
        $this->company->users()->attach($this->admin->getKey());

        $this->actingAs($this->admin);
        $this->setCurrentTenant($this->company);
    }

    private function setModule(string $module, bool $on): void
    {
        CompanyModule::updateOrCreate(
            ['company_id' => $this->company->getKey(), 'module' => $module],
            ['licensed' => $on, 'enabled' => $on],
        );

        modules()->flush();
    }

    private function permission(string $name): Permission
    {
        return Permission::where('name', $name)->firstOrFail();
    }

    private function roleWith(string ...$permissions): Role
    {
        $role = Role::create(['name' => 'Bookkeeper', 'guard_name' => 'web']);

        $role->permissions()->sync(
            collect($permissions)->map(fn (string $name) => $this->permission($name)->getKey())->all()
        );

        return $role;
    }

    public function test_saving_a_role_does_not_strip_a_disabled_modules_permissions(): void
    {
        // The data-loss guard. AccountView belongs to Accounting; UserView to Core.
        $role = $this->roleWith('AccountView', 'UserView');

        $this->setModule('accounting', false);

        Livewire::test(EditRole::class, ['record' => $role->getKey()])
            ->fillForm([RoleForm::groupKey('User') => [$this->permission('UserView')->getKey()]])
            ->call('save')
            ->assertHasNoFormErrors();

        $role->refresh();

        $this->assertTrue(
            $role->permissions->contains('name', 'AccountView'),
            'A permission belonging to a switched-off module must survive a role save — it is '
            .'not on the form, so it cannot have been deselected.'
        );
        $this->assertTrue($role->permissions->contains('name', 'UserView'));
    }

    public function test_a_visible_permission_can_still_be_removed(): void
    {
        // The preservation must not turn into "nothing can ever be unchecked".
        $role = $this->roleWith('AccountView', 'UserView');

        $this->setModule('accounting', true);

        Livewire::test(EditRole::class, ['record' => $role->getKey()])
            ->fillForm([RoleForm::groupKey('Account') => []])
            ->call('save');

        $role->refresh();

        $this->assertFalse($role->permissions->contains('name', 'AccountView'));
    }

    public function test_a_disabled_modules_groups_are_not_listed(): void
    {
        $this->setModule('accounting', false);

        $groups = RoleForm::groupedPermissions()->keys()->all();

        $this->assertNotContains('Account', $groups);
        $this->assertNotContains('Report', $groups, 'Report belongs to Accounting even though it is not named for a model.');
        $this->assertContains('User', $groups, 'Core groups always stay.');
    }

    public function test_an_enabled_modules_groups_are_listed(): void
    {
        $this->setModule('accounting', true);

        $groups = RoleForm::groupedPermissions()->keys()->all();

        $this->assertContains('Account', $groups);
        $this->assertContains('Report', $groups);
    }

    public function test_the_form_hides_the_disabled_groups_checkbox_list(): void
    {
        $role = $this->roleWith('UserView');

        $this->setModule('inventory', false);

        Livewire::test(EditRole::class, ['record' => $role->getKey()])
            ->assertFormFieldDoesNotExist(RoleForm::groupKey('Inventory'))
            ->assertFormFieldExists(RoleForm::groupKey('User'));
    }

    public function test_re_enabling_a_module_finds_its_role_permissions_intact(): void
    {
        // The end-to-end version of the first test: the round trip a customer
        // actually performs.
        $role = $this->roleWith('AccountView', 'JournalEntryPost', 'UserView');

        $this->setModule('accounting', false);

        Livewire::test(EditRole::class, ['record' => $role->getKey()])
            ->call('save');

        $this->setModule('accounting', true);

        $role->refresh();

        $this->assertTrue($role->permissions->contains('name', 'AccountView'));
        $this->assertTrue($role->permissions->contains('name', 'JournalEntryPost'));
    }
}
