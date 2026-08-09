<?php

namespace Tests\Feature;

use App\Modules\Core\Filament\Resources\Roles\Pages\CreateRole;
use App\Modules\Core\Filament\Resources\Roles\Pages\ListRoles;
use App\Modules\Core\Filament\Resources\Roles\RoleResource;
use App\Modules\Core\Models\Company;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\AccountingTestCase;
use Tests\Concerns\InteractsWithTenant;

/**
 * Roles belong to one company, and the Roles screen shows that company's.
 *
 * Every role row carries a `company_id` (spatie teams), so provisioning a second
 * company correctly creates a second set. The resource queried the table with no
 * company filter, though, so the list showed every company's — Administrator, Employee,
 * Accountant, Manager and CEO each appearing once per company. Creating a company
 * looked like it had duplicated the roles, and with three companies each name appeared
 * three times with nothing on screen to tell them apart.
 */
class RolesAreScopedToTheCompanyTest extends AccountingTestCase
{
    use InteractsWithTenant;

    private Company $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->makeUser('Administrator', 'roles@test.local'));
        $this->setCurrentTenant();

        // A second company with its own set, which is what provisioning creates.
        $this->other = Company::factory()->create(['name' => 'Other AG']);
        $this->seedRolesFor($this->other);
        $this->seedRolesFor($this->tenant);
    }

    private function seedRolesFor(Company $company): void
    {
        $registrar = app(PermissionRegistrar::class);
        $previous = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($company->getKey());

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $registrar->setPermissionsTeamId($previous);
        $registrar->forgetCachedPermissions();
    }

    public function test_both_companies_really_do_have_their_own_roles(): void
    {
        // Not a duplicate: a role is per company, and one company's Accountant is not
        // the other's.
        $this->assertSame(5, Role::where('company_id', $this->tenant->getKey())->count());
        $this->assertSame(5, Role::where('company_id', $this->other->getKey())->count());
    }

    public function test_the_list_shows_only_this_companys_roles(): void
    {
        Livewire::test(ListRoles::class)
            ->assertCanSeeTableRecords(Role::where('company_id', $this->tenant->getKey())->get())
            ->assertCanNotSeeTableRecords(Role::where('company_id', $this->other->getKey())->get());
    }

    public function test_each_name_appears_once(): void
    {
        // The symptom: five names, ten rows.
        $records = RoleResource::getEloquentQuery()->get();

        $this->assertSame(5, $records->count());
        $this->assertSame(5, $records->pluck('name')->unique()->count());
    }

    public function test_a_role_created_here_belongs_to_this_company(): void
    {
        Livewire::test(CreateRole::class)
            ->fillForm(['name' => 'Auditor'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(
            $this->tenant->getKey(),
            Role::where('name', 'Auditor')->value('company_id'),
        );
    }

    public function test_another_companys_role_cannot_be_reached_by_its_id(): void
    {
        // Otherwise the list is a display detail rather than a boundary.
        $theirs = Role::where('company_id', $this->other->getKey())->where('name', 'CEO')->firstOrFail();

        $this->assertNull(RoleResource::getEloquentQuery()->find($theirs->getKey()));
    }

    public function test_two_companies_may_each_have_a_role_of_the_same_name(): void
    {
        $this->assertSame(
            2,
            Role::where('name', 'Administrator')->count(),
            'which is why the list has to say which company it is showing',
        );
    }
}
