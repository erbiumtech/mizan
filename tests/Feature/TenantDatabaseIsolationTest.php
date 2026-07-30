<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Bank;
use App\Modules\Core\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Proves the database-per-tenant switch works end to end: two companies backed
 * by two separate SQLite files, each with its own isolated set of tenant tables.
 */
class TenantDatabaseIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected string $tenantDir;

    protected array $tenantFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Activate real per-tenant switching (tests otherwise share the default DB).
        config(['multitenancy.tenant_database_connection_name' => 'tenant']);

        $this->tenantDir = storage_path('framework/testing/tenants');
        File::ensureDirectoryExists($this->tenantDir);
    }

    protected function tearDown(): void
    {
        Company::forgetCurrent();

        foreach ($this->tenantFiles as $file) {
            File::delete($file);
        }

        parent::tearDown();
    }

    protected function makeTenant(string $key): Company
    {
        $file = "{$this->tenantDir}/{$key}-".uniqid().'.sqlite';
        touch($file);
        $this->tenantFiles[] = $file;

        return Company::factory()->create(['database' => $file]);
    }

    protected function migrateCurrentTenant(): void
    {
        Artisan::call('migrate', [
            '--database' => 'tenant',
            '--path' => 'database/migrations/tenant',
            '--force' => true,
        ]);
    }

    public function test_each_company_gets_an_isolated_tenant_database(): void
    {
        $companyA = $this->makeTenant('company-a');
        $companyB = $this->makeTenant('company-b');

        $companyA->makeCurrent();
        $this->migrateCurrentTenant();
        Bank::create(['bank_code' => 'AAA', 'bank_name' => 'Bank A', 'is_active' => true]);

        $companyB->makeCurrent();
        $this->migrateCurrentTenant();
        Bank::create(['bank_code' => 'BBB', 'bank_name' => 'Bank B', 'is_active' => true]);

        // Company B only sees its own bank.
        $companyB->makeCurrent();
        $this->assertSame(1, Bank::count());
        $this->assertSame('Bank B', Bank::first()->bank_name);

        // Company A only sees its own bank.
        $companyA->makeCurrent();
        $this->assertSame(1, Bank::count());
        $this->assertSame('Bank A', Bank::first()->bank_name);

        // Neither tenant's data leaked into the landlord/default connection.
        $default = config('database.default');
        $this->assertSame(0, \DB::connection($default)->table('banks')->count());
    }
}
