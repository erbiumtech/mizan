<?php

namespace Tests\Feature;

use App\Modules\Accounting\Filament\Resources\JournalEntries\JournalEntryResource;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\CompanyModule;
use App\Modules\Core\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Who may reach the Journal Entries resource at all. Previously asserted as a
 * side effect of JournalEntryHelp sharing the same JournalEntryView gate; now
 * that help has no gate of its own (see JournalEntryHelpTest), this is
 * asserted against the resource directly.
 */
class JournalEntryResourceTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private function actAs(Company $company, string $role): User
    {
        $this->seed(PermissionSeeder::class);

        app(PermissionRegistrar::class)->setPermissionsTeamId($company->getKey());
        (new RoleSeeder)->run();

        $user = User::factory()->create(['status' => 1]);
        $company->users()->attach($user->getKey());
        $user->assignRole($role);

        $this->actingAs($user);
        $this->setCurrentTenant($company);

        return $user;
    }

    public function test_every_role_that_can_view_journal_entries_can_access_the_resource(): void
    {
        foreach (['Accountant', 'Manager', 'CEO'] as $role) {
            $this->actAs(Company::factory()->create(), $role);

            $this->assertTrue(JournalEntryResource::canAccess(), "{$role} was denied Journal Entries");
        }
    }

    public function test_an_employee_cannot_access_the_resource(): void
    {
        // Employee holds no Journal Entry permission at all.
        $this->actAs(Company::factory()->create(), 'Employee');

        $this->assertFalse(JournalEntryResource::canAccess());
    }

    public function test_a_disabled_accounting_module_takes_the_resource_down_with_it(): void
    {
        $company = Company::factory()->create();
        $this->actAs($company, 'Accountant');

        CompanyModule::updateOrCreate(
            ['company_id' => $company->getKey(), 'module' => 'accounting'],
            ['licensed' => false, 'enabled' => false],
        );
        modules()->flush();

        $this->assertFalse(JournalEntryResource::canAccess());
    }
}
