<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
use App\Multitenancy\CompanyProvisioner;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CompanyProvisioningTest extends TestCase
{
    use RefreshDatabase;

    protected array $provisionedFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Provisioning requires a dedicated, switchable tenant connection.
        config(['multitenancy.tenant_database_connection_name' => 'tenant']);
    }

    protected function tearDown(): void
    {
        Company::forgetCurrent();

        foreach (Company::all() as $company) {
            if ($company->database && File::exists($company->database)) {
                File::delete($company->database);
            }
        }

        parent::tearDown();
    }

    /**
     * `CREATE DATABASE IF NOT EXISTS` would otherwise adopt a leftover schema
     * and fail deep inside `migrate` on a table an earlier run had created.
     */
    public function test_provisioning_refuses_a_tenant_database_that_already_has_data(): void
    {
        $this->seed(PermissionSeeder::class);

        $path = database_path('tenants/stale-co.sqlite');
        File::ensureDirectoryExists(dirname($path));
        $this->provisionedFiles[] = $path;

        // A non-empty leftover database at the slug we are about to claim.
        File::put($path, 'not empty');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/already exists and contains tables/');

        try {
            app(CompanyProvisioner::class)->provision(name: 'Stale Co', slug: 'stale-co');
        } finally {
            // Refused before anything was written.
            $this->assertDatabaseMissing('companies', ['slug' => 'stale-co']);
            File::delete($path);
        }
    }

    /** A failure part-way through must not leave an orphan company + database. */
    public function test_a_failed_provision_rolls_back_the_company_and_its_database(): void
    {
        $this->seed(PermissionSeeder::class);

        $path = database_path('tenants/doomed-co.sqlite');
        $this->provisionedFiles[] = $path;

        // Fails after the database has been created and migrated.
        $provisioner = new class extends CompanyProvisioner
        {
            protected function attachCreator(Company $company, User $creator): void
            {
                throw new \RuntimeException('boom');
            }
        };

        try {
            $provisioner->provision(name: 'Doomed Co', slug: 'doomed-co', creator: User::factory()->create());
            $this->fail('provisioning should have thrown');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage(), 'the original failure must surface, not a rollback error');
        }

        $this->assertDatabaseMissing('companies', ['slug' => 'doomed-co']);
        $this->assertFalse(File::exists($path), 'the tenant database should have been removed');

        // Roles are landlord rows keyed on company_id with no foreign key to
        // cascade them, so deleting the company used to leave them behind. They
        // belong to no company and are reachable by nobody, but they are still
        // rows named Administrator and Employee — and they broke editing a real
        // company's roles, because the name uniqueness rule counted them.
        $this->assertSame(
            0,
            Role::whereNotIn('company_id', Company::pluck('id'))->count(),
            'the rolled-back company left orphan roles behind',
        );
    }

    /**
     * Provisioning must not depend on somebody having re-seeded permissions.
     *
     * Deliberately does NOT seed PermissionSeeder first, unlike every other test
     * here — and that omission is the point. RoleSeeder calls syncPermissions()
     * with a list of literal names, and Spatie throws PermissionDoesNotExist for
     * a name it cannot find rather than skipping it. So the moment a release
     * adds a permission, creating a company 500s until somebody remembers to
     * re-seed the landlord permissions table, with no hint that is what is
     * wrong.
     *
     * It happened: adding the Personal Finance permissions broke company
     * creation in exactly this way, and every test here passed throughout
     * because they all pre-seed the precondition instead of asserting
     * provisioning establishes it.
     */
    public function test_provisioning_works_without_permissions_being_seeded_first(): void
    {
        $this->assertSame(0, Permission::count(), 'precondition: no permissions seeded');

        $owner = User::factory()->create();

        $company = app(CompanyProvisioner::class)->provision(
            name: 'Fresh Install Co',
            creator: $owner,
        );

        $this->provisionedFiles[] = $company->database;

        $company->makeCurrent();
        $this->assertTrue(
            $owner->fresh()->hasRole('Administrator'),
            'The owner did not get their role, so RoleSeeder did not complete.',
        );
        Company::forgetCurrent();

        // And the roles really do carry their permissions, rather than having
        // been quietly created empty.
        $this->assertGreaterThan(0, Permission::count());
    }

    public function test_provisioning_creates_isolated_seeded_tenant_and_attaches_owner(): void
    {
        // Global permissions must exist; provisioning creates the per-company roles.
        $this->seed(PermissionSeeder::class);
        $owner = User::factory()->create();

        $company = app(CompanyProvisioner::class)->provision(
            name: 'Acme Industries',
            creator: $owner,
        );

        // Landlord record + isolated database file created.
        $this->assertDatabaseHas('companies', ['id' => $company->id, 'name' => 'Acme Industries']);
        $this->assertTrue(File::exists($company->database));

        // Baseline chart of accounts seeded into the tenant database.
        $company->makeCurrent();
        $this->assertGreaterThan(0, Account::count());

        // And a currency to keep its books in. Without one the company has nothing on
        // its Currencies screen and no row saying what its posted amounts mean.
        $this->assertSame('PKR', \App\Modules\Accounting\Models\Currency::baseCode());
        $this->assertGreaterThan(0, \App\Modules\Accounting\Models\Currency::count());

        // The accounts exchange differences are posted to, which the revaluation and
        // settlement paths both refuse to invent.
        $this->assertNotNull(Account::where('code', '4400')->first(), 'unrealised exchange gain / (loss)');
        $this->assertNotNull(Account::where('code', '4450')->first(), 'realised exchange gain / (loss)');
        Company::forgetCurrent();

        // Owner is a member with the Administrator role in this company's team.
        $this->assertTrue($company->users()->where('users.id', $owner->id)->exists());
        $company->makeCurrent();
        $this->assertTrue($owner->fresh()->hasRole('Administrator'));
        Company::forgetCurrent();

        // Baseline data did not leak into the landlord/default database.
        $default = config('database.default');
        $this->assertSame(0, \DB::connection($default)->table('accounts')->count());
    }
}
