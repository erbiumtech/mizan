<?php

namespace Tests\Feature;

use App\Modules\Core\Filament\Resources\Roles\Pages\CreateRole;
use App\Modules\Core\Filament\Resources\Roles\Pages\EditRole;
use App\Modules\Core\Filament\Resources\Roles\Schemas\RoleForm;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

class FilamentRoleResourceTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::before(fn () => true);
        $this->actingAs(User::factory()->create());
        $this->setCurrentTenant();

        Permission::create(['name' => 'AlphaView', 'group' => 'Alpha', 'guard_name' => 'web']);
        Permission::create(['name' => 'AlphaEdit', 'group' => 'Alpha', 'guard_name' => 'web']);
        Permission::create(['name' => 'BetaView', 'group' => 'Beta', 'guard_name' => 'web']);
    }

    /**
     * Every company has a role called Employee. Editing one of them must not
     * collide with the others.
     *
     * The name field was `unique(ignoreRecord: true)`, which excludes only the row
     * being edited and then checks the whole roles table — so with more than one
     * company in the installation, opening any seeded role and pressing Save
     * failed with "The name has already been taken." Nothing about the message
     * points at the real cause, and the role being edited is plainly the only one
     * of its name in the company you are looking at.
     *
     * Roles are per-company (company_id is spatie's team key) and the resource's
     * own getEloquentQuery() scopes to it, but a Filament unique rule builds its
     * own query and never goes through that.
     */
    public function test_a_role_can_be_edited_when_another_company_has_one_of_the_same_name(): void
    {
        $mine = Role::create([
            'name' => 'Employee',
            'guard_name' => 'web',
            'company_id' => Company::current()?->getKey() ?? $this->tenant->getKey(),
        ]);

        // A second company with the same role name — the ordinary state of this
        // application, not an edge case.
        $other = Company::factory()->create();
        Role::create(['name' => 'Employee', 'guard_name' => 'web', 'company_id' => $other->getKey()]);

        Livewire::test(EditRole::class, ['record' => $mine->getKey()])
            ->fillForm(['name' => 'Employee', 'guard_name' => 'web'])
            ->call('save')
            ->assertHasNoFormErrors();
    }

    public function test_two_roles_in_the_same_company_still_cannot_share_a_name(): void
    {
        $companyId = Company::current()?->getKey() ?? $this->tenant->getKey();

        Role::create(['name' => 'Auditor', 'guard_name' => 'web', 'company_id' => $companyId]);
        $second = Role::create(['name' => 'Reviewer', 'guard_name' => 'web', 'company_id' => $companyId]);

        // The rule still has to do its job within a company; scoping it must not
        // turn it off.
        Livewire::test(EditRole::class, ['record' => $second->getKey()])
            ->fillForm(['name' => 'Auditor', 'guard_name' => 'web'])
            ->call('save')
            ->assertHasFormErrors(['name']);
    }

    public function test_create_role_assigns_grouped_permissions(): void
    {
        $alpha = Permission::whereIn('name', ['AlphaView', 'AlphaEdit'])->pluck('id')->all();

        Livewire::test(CreateRole::class)
            ->fillForm([
                'name' => 'Auditor',
                'guard_name' => 'web',
                RoleForm::groupKey('Alpha') => $alpha,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $role = Role::where('name', 'Auditor')->firstOrFail();
        $this->assertEqualsCanonicalizing(['AlphaView', 'AlphaEdit'], $role->permissions->pluck('name')->all());
    }

    public function test_edit_role_hydrates_and_updates_permissions(): void
    {
        $role = Role::create(['name' => 'Editor', 'guard_name' => 'web']);
        $role->givePermissionTo('AlphaView');

        $component = Livewire::test(EditRole::class, ['record' => $role->getKey()]);

        // Hydration: the Alpha CheckboxList should pre-check AlphaView.
        $alphaViewId = Permission::where('name', 'AlphaView')->value('id');
        $component->assertFormSet([RoleForm::groupKey('Alpha') => [$alphaViewId]]);

        // Swap to a different group and save.
        $betaId = Permission::where('name', 'BetaView')->value('id');
        $component
            ->fillForm([
                RoleForm::groupKey('Alpha') => [],
                RoleForm::groupKey('Beta') => [$betaId],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertEqualsCanonicalizing(['BetaView'], $role->fresh()->permissions->pluck('name')->all());
    }
}
