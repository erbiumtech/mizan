<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Company;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Seeding roles for a company, and for all of them.
 *
 * Roles are per-company (spatie teams), so every role row carries a `company_id` and
 * the seeder reads it from the permission registrar. Run outside a tenant — plain
 * `db:seed --class=RoleSeeder`, which is what somebody naturally types — the team id is
 * null, and a null team is not a company: it produced a full set of roles belonging to
 * nobody, holding every permission, while leaving each real company's roles exactly as
 * they were. It reported success and changed nothing that mattered.
 */
class RoleSeedingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
    }

    private function company(string $name, string $slug): Company
    {
        return Company::factory()->create(['name' => $name, 'slug' => $slug]);
    }

    private function withoutTeam(): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_it_seeds_every_company_when_no_company_is_current(): void
    {
        $first = $this->company('First AG', 'first-ag');
        $second = $this->company('Second AG', 'second-ag');

        $this->withoutTeam();
        $this->seed(RoleSeeder::class);

        foreach ([$first, $second] as $company) {
            $this->assertSame(
                5,
                Role::where('company_id', $company->id)->count(),
                "{$company->name} has its own set of roles",
            );
        }
    }

    /** The bug: roles belonging to no company, reachable by nobody. */
    public function test_it_never_creates_a_role_belonging_to_no_company(): void
    {
        $this->company('First AG', 'first-ag');

        $this->withoutTeam();
        $this->seed(RoleSeeder::class);

        $this->assertSame(0, Role::whereNull('company_id')->count());
    }

    public function test_it_seeds_only_the_current_company_when_there_is_one(): void
    {
        $first = $this->company('First AG', 'first-ag');
        $second = $this->company('Second AG', 'second-ag');

        app(PermissionRegistrar::class)->setPermissionsTeamId($first->id);
        $this->seed(RoleSeeder::class);

        $this->assertSame(5, Role::where('company_id', $first->id)->count());
        $this->assertSame(0, Role::where('company_id', $second->id)->count(), 'the other company is not touched');
    }

    public function test_running_it_twice_does_not_double_the_roles(): void
    {
        $company = $this->company('First AG', 'first-ag');

        $this->withoutTeam();
        $this->seed(RoleSeeder::class);
        $this->withoutTeam();
        $this->seed(RoleSeeder::class);

        $this->assertSame(5, Role::where('company_id', $company->id)->count());
    }

    public function test_it_picks_up_permissions_added_since_the_last_run(): void
    {
        // Which is the whole reason to re-run it: the code gains a permission, and
        // until this runs no role holds it, so the feature it guards reaches nobody.
        $company = $this->company('First AG', 'first-ag');

        $this->withoutTeam();
        $this->seed(RoleSeeder::class);

        $accountant = Role::where('company_id', $company->id)->where('name', 'Accountant')->firstOrFail();

        $this->assertTrue(
            $accountant->permissions->pluck('name')->contains('ExpenseClaimApprove'),
            'the Accountant can decide an expense claim',
        );
    }

    public function test_the_employee_role_cannot_approve_its_own_claims(): void
    {
        // An approver is somebody else — that is the point of one.
        $company = $this->company('First AG', 'first-ag');

        $this->withoutTeam();
        $this->seed(RoleSeeder::class);

        $employee = Role::where('company_id', $company->id)->where('name', 'Employee')->firstOrFail();
        $names = $employee->permissions->pluck('name');

        $this->assertTrue($names->contains('ExpenseClaimCreate'));
        $this->assertFalse($names->contains('ExpenseClaimApprove'));
    }

    public function test_a_company_added_later_is_seeded_by_the_next_run(): void
    {
        $this->company('First AG', 'first-ag');

        $this->withoutTeam();
        $this->seed(RoleSeeder::class);

        $late = $this->company('Late AG', 'late-ag');

        $this->withoutTeam();
        $this->seed(RoleSeeder::class);

        $this->assertSame(5, Role::where('company_id', $late->id)->count());
    }
}
