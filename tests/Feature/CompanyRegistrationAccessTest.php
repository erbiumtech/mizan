<?php

namespace Tests\Feature;

use App\Modules\Core\Filament\Pages\Tenancy\RegisterCompany;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Registering a company provisions a database, migrates it and seeds its roles —
 * an installation-level act. It is the super admin's, not a customer
 * administrator's, and the rest of company management is already gated that way.
 */
class CompanyRegistrationAccessTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    protected function makeCompanyWithRoles(): Company
    {
        $company = Company::factory()->create();
        app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
        (new RoleSeeder)->run();

        return $company;
    }

    public function test_a_company_administrator_cannot_create_companies(): void
    {
        $this->seed(PermissionSeeder::class);
        $company = $this->makeCompanyWithRoles();

        $admin = User::factory()->create();
        $company->users()->attach($admin);
        app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
        $admin->assignRole('Administrator');

        $this->assertFalse($admin->canCreateCompanies());

        // Administrators pass Gate::before for everything but 'create', which is
        // what leaves this policy in charge of the answer.
        $this->actingAs($admin);
        $this->assertFalse(Gate::allows('create', Company::class));
    }

    public function test_a_non_administrator_cannot_create_companies(): void
    {
        $this->seed(PermissionSeeder::class);
        $company = $this->makeCompanyWithRoles();

        $employee = User::factory()->create();
        $company->users()->attach($employee);
        app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
        $employee->assignRole('Employee');

        $this->assertFalse($employee->canCreateCompanies());
    }

    public function test_a_super_admin_can_create_companies(): void
    {
        $this->seed(PermissionSeeder::class);
        $company = $this->makeCompanyWithRoles();

        $superAdmin = User::factory()->create(['is_super_admin' => true]);
        $company->users()->attach($superAdmin);

        $this->assertTrue($superAdmin->canCreateCompanies());

        $this->actingAs($superAdmin);
        $this->assertTrue(Gate::allows('create', Company::class));
    }

    /**
     * The page, not just the menu entry: Filament aborts the registration route
     * on canView(), so a company administrator who knows the URL gets a 404.
     */
    public function test_the_registration_page_is_closed_to_a_company_administrator(): void
    {
        $this->seed(PermissionSeeder::class);
        $company = $this->makeCompanyWithRoles();

        $admin = User::factory()->create();
        $company->users()->attach($admin);
        app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
        $admin->assignRole('Administrator');

        $this->actingAs($admin);
        $this->setCurrentTenant($company);

        $this->assertFalse(RegisterCompany::canView());
        $this->get(Filament::getTenantRegistrationUrl())->assertNotFound();
    }

    public function test_the_registration_page_is_open_to_a_super_admin(): void
    {
        $this->seed(PermissionSeeder::class);
        $company = $this->makeCompanyWithRoles();

        $superAdmin = User::factory()->create(['is_super_admin' => true]);
        $company->users()->attach($superAdmin);

        $this->actingAs($superAdmin);
        $this->setCurrentTenant($company);

        $this->assertTrue(RegisterCompany::canView());
        $this->get(Filament::getTenantRegistrationUrl())->assertOk();
    }
}
