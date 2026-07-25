<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * 5.9 — a single user can hold different roles in different companies (spatie
 * teams keyed by company id), and membership gates tenant access.
 */
class PerCompanyRoleTest extends TestCase
{
    use RefreshDatabase;

    protected function seedRolesFor(Company $company): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($company->getKey());
        (new RoleSeeder)->run();
    }

    public function test_same_user_has_different_roles_per_company(): void
    {
        $this->seed(PermissionSeeder::class);

        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $this->seedRolesFor($companyA);
        $this->seedRolesFor($companyB);

        $user = User::factory()->create();
        $companyA->users()->attach($user);
        $companyB->users()->attach($user);

        $registrar = app(PermissionRegistrar::class);

        // Accountant in A…
        $registrar->setPermissionsTeamId($companyA->getKey());
        $user->assignRole('Accountant');

        // …Manager in B.
        $registrar->setPermissionsTeamId($companyB->getKey());
        $user->assignRole('Manager');

        // Resolve per company.
        $registrar->setPermissionsTeamId($companyA->getKey());
        $this->assertTrue($user->fresh()->hasRole('Accountant'));
        $this->assertFalse($user->fresh()->hasRole('Manager'));
        $this->assertTrue($user->fresh()->can('PaymentCreate'));
        $this->assertFalse($user->fresh()->can('JournalEntryApprove'));

        $registrar->setPermissionsTeamId($companyB->getKey());
        $this->assertTrue($user->fresh()->hasRole('Manager'));
        $this->assertFalse($user->fresh()->hasRole('Accountant'));
        $this->assertTrue($user->fresh()->can('JournalEntryApprove'));

        $registrar->setPermissionsTeamId(null);
    }

    public function test_membership_gates_tenant_access(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $user = User::factory()->create();
        $companyA->users()->attach($user);

        $this->assertTrue($user->canAccessTenant($companyA));
        $this->assertFalse($user->canAccessTenant($companyB));
    }
}
