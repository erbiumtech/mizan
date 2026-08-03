<?php

namespace Tests\Feature;

use App\Filament\Pages\CompanySettings;
use App\Filament\Resources\CustomFields\CustomFieldResource;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * A super admin switching into a company they hold no role in.
 *
 * Gate::before already says a super admin may do anything, so policies are
 * covered — but a page's canAccess() and an action's visible() never go through
 * the Gate. Every one of those asking `hasRole('Administrator')` on its own quietly
 * excluded the one account that outranks the question: super admins are not
 * members of most companies, let alone role-holders in them, they simply switch
 * in. Screens went missing with no error to explain it.
 *
 * The fixture is the whole point — a super admin with no roles anywhere, in a
 * company they do not belong to. A test that gave them the Administrator role
 * would pass against the bug.
 */
class SuperAdminReachTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private Company $company;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);

        $this->company = Company::factory()->create();
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->getKey());
        (new RoleSeeder)->run();

        $this->superAdmin = User::factory()->create(['is_super_admin' => true, 'status' => 1]);

        $this->actingAs($this->superAdmin);
        $this->setCurrentTenant($this->company);
    }

    public function test_the_fixture_is_a_super_admin_with_no_role_here(): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->getKey());

        $this->assertTrue($this->superAdmin->isSuperAdmin());
        $this->assertFalse($this->superAdmin->hasRole('Administrator'));
        $this->assertFalse($this->superAdmin->companies()->whereKey($this->company->getKey())->exists());

        $this->assertTrue($this->superAdmin->isAdministrator());
    }

    public function test_company_settings_is_reachable(): void
    {
        $this->assertTrue(CompanySettings::canAccess());

        $this->get(CompanySettings::getUrl(tenant: $this->company))->assertOk();
    }

    public function test_custom_fields_is_reachable(): void
    {
        $this->assertTrue(CustomFieldResource::canAccess());
    }

    /**
     * Deactivating an account is the sort of thing a platform owner does, and the
     * button was hidden from them.
     */
    public function test_the_activate_deactivate_action_is_offered(): void
    {
        $member = User::factory()->create(['status' => 1]);
        $this->company->users()->attach($member);

        Livewire::test(ListUsers::class)
            ->assertActionVisible(TestAction::make('toggleStatus')->table($member->getKey()));
    }

    /** An Administrator of the company keeps everything they had. */
    public function test_a_company_administrator_is_unaffected(): void
    {
        $admin = User::factory()->create(['status' => 1]);
        $this->company->users()->attach($admin);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->getKey());
        $admin->assignRole('Administrator');

        $this->actingAs($admin);

        $this->assertTrue($admin->isAdministrator());
        $this->assertTrue(CompanySettings::canAccess());
    }

    /** And an ordinary employee still gets none of it. */
    public function test_an_employee_is_still_kept_out(): void
    {
        $employee = User::factory()->create(['status' => 1]);
        $this->company->users()->attach($employee);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->getKey());
        $employee->assignRole('Employee');

        $this->actingAs($employee);

        $this->assertFalse($employee->isAdministrator());
        $this->assertFalse(CompanySettings::canAccess());
        $this->assertFalse(CustomFieldResource::canAccess());
    }
}
