<?php

namespace Tests\Concerns;

use App\Models\Company;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Runs a test against a genuine two-database setup: the landlord on the default
 * connection, and the tenant on its own SQLite file.
 *
 * Why this exists
 * ---------------
 * The rest of the suite collapses both databases into one, because tenant
 * migrations are auto-loaded onto the default connection in the testing
 * environment. That is fast, and fine for domain logic — but it makes an entire
 * class of bug invisible: any query that spans the two databases works locally
 * and dies in production. Exactly that shipped, twice:
 *
 *   Base table or view not found: 1146 Table 'tenant_….users' doesn't exist
 *
 * caused by `whereHas('user', …)` compiling to one statement that names the
 * landlord `users` table while running on the tenant connection.
 *
 * Here the tenant database really does lack `users`, `companies` and the
 * permission tables, so such a query throws — which is the point.
 *
 * Usage
 * -----
 *   class MyTest extends TestCase
 *   {
 *       use RefreshDatabase, UsesRealTenantDatabase;
 *
 *       protected function setUp(): void
 *       {
 *           parent::setUp();
 *           $this->bootRealTenant();                       // landlord + tenant
 *           $this->seedTenant([FiscalYearSeeder::class]);  // tenant-side data
 *       }
 *   }
 *
 * Known limitation: the landlord connection still carries a copy of the tenant
 * tables (RefreshDatabase migrates every registered path onto it), so a *tenant*
 * model that wrongly ran on the landlord connection would still find its table.
 * This catches the tenant→landlord direction, which is the one that has bitten
 * us; the reverse would need the tenant migrations removed from the default
 * connection.
 */
trait UsesRealTenantDatabase
{
    protected ?Company $realTenant = null;

    protected array $realTenantFiles = [];

    /**
     * Point the `tenant` connection at a fresh SQLite file, migrate the tenant
     * schema into it, and make the company current.
     */
    protected function bootRealTenant(?string $name = null): Company
    {
        // Without this the tenant connection name is null and TenantModel falls
        // back to the default connection — i.e. the single-database setup.
        config(['multitenancy.tenant_database_connection_name' => 'tenant']);

        $directory = storage_path('framework/testing/tenants');
        File::ensureDirectoryExists($directory);

        $file = $directory.'/'.($name ?? 'tenant').'-'.uniqid().'.sqlite';
        touch($file);
        $this->realTenantFiles[] = $file;

        $this->realTenant = Company::factory()->create([
            'database' => $file,
        ] + ($name ? ['name' => $name] : []));

        $this->realTenant->makeCurrent();

        Artisan::call('migrate', [
            '--database' => 'tenant',
            '--path' => 'database/migrations/tenant',
            '--force' => true,
        ]);

        // Deleted after the app is torn down, so the connection is closed first.
        $this->beforeApplicationDestroyed(function (): void {
            Company::forgetCurrent();

            foreach ($this->realTenantFiles as $path) {
                File::delete($path);
            }
        });

        return $this->realTenant;
    }

    /**
     * Run seeders with the tenant current, so their tables are the tenant's.
     *
     * @param  array<int, class-string>  $seeders
     */
    protected function seedTenant(array $seeders): void
    {
        foreach ($seeders as $seeder) {
            $this->seed($seeder);
        }
    }

    /**
     * Sign a user in and make the panel tenant-aware, for Livewire page tests.
     *
     * A role is needed for more than authorization: the employee-keyed listings
     * scope rows to "own + downline" via ScopesToAccessibleEmployees, so a user
     * with no privileged role sees an empty table regardless of the Gate.
     *
     * Roles are per-company, so they are seeded after the tenant is current and
     * the permission team id has been set by SetPermissionsTeamIdTask.
     */
    protected function actingAsTenantUser(?User $user = null, string $role = 'Administrator'): User
    {
        $user ??= User::factory()->create();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $user->companies()->attach($this->realTenant);
        $user->assignRole($role);

        $this->actingAs($user);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->realTenant);
        app()->instance('currentTenant', $this->realTenant);

        return $user;
    }

    /**
     * Assert the two databases really are separate, so a test that relies on it
     * fails loudly rather than silently passing on a shared connection.
     */
    protected function assertTenantDatabaseIsSeparate(): void
    {
        $this->assertNotSame(
            config('database.default'),
            config('multitenancy.tenant_database_connection_name'),
            'the tenant connection must differ from the landlord one'
        );

        $tenantTables = collect(DB::connection('tenant')->select(
            "select name from sqlite_master where type = 'table'"
        ))->pluck('name');

        $this->assertTrue($tenantTables->contains('employees'), 'tenant schema should be migrated');
        $this->assertFalse($tenantTables->contains('users'), 'users belongs to the landlord database');
    }
}
