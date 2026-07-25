<?php

namespace Tests\Feature;

use App\Filament\Resources\Roles\Pages\CreateRole;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Roles\Schemas\RoleForm;
use App\Models\User;
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
