<?php

namespace Tests\Feature;

use App\Modules\Core\Filament\Resources\Users\Pages\EditUser;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

class UserRoleAssignmentTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    public function test_admin_assigns_roles_scoped_to_current_company(): void
    {
        Gate::before(fn () => true);
        $this->seed(PermissionSeeder::class);

        $company = Company::factory()->create();
        app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
        (new RoleSeeder)->run();

        $admin = User::factory()->create();
        $this->actingAs($admin);
        $this->setCurrentTenant($company);

        // A member of this company: UserResource scopes to the `company_user`
        // pivot, so a non-member is not editable from this company's panel — which
        // is the isolation UserTenantScopingTest asserts. Creating a user with the
        // panel serving a company attaches them to it already (same scoping,
        // write side), hence sync rather than attach.
        $target = User::factory()->create();
        $company->users()->syncWithoutDetaching([$target->getKey()]);

        Livewire::test(EditUser::class, ['record' => $target->getKey()])
            ->assertSuccessful()
            ->set('data.roles', ['Accountant', 'Manager'])
            ->call('save')
            ->assertHasNoErrors();

        app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
        $this->assertEqualsCanonicalizing(['Accountant', 'Manager'], $target->fresh()->roles()->pluck('name')->all());
    }
}
