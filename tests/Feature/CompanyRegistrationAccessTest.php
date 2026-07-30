<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CompanyRegistrationAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function makeCompanyWithRoles(): Company
    {
        $company = Company::factory()->create();
        app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
        (new RoleSeeder)->run();

        return $company;
    }

    public function test_administrator_of_a_company_can_create_companies(): void
    {
        $this->seed(PermissionSeeder::class);
        $company = $this->makeCompanyWithRoles();

        $admin = User::factory()->create();
        $company->users()->attach($admin);
        app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
        $admin->assignRole('Administrator');

        $this->assertTrue($admin->canCreateCompanies());
    }

    public function test_non_administrator_cannot_create_companies(): void
    {
        $this->seed(PermissionSeeder::class);
        $company = $this->makeCompanyWithRoles();

        $employee = User::factory()->create();
        $company->users()->attach($employee);
        app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
        $employee->assignRole('Employee');

        $this->assertFalse($employee->canCreateCompanies());
    }
}
