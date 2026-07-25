<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use App\Multitenancy\CompanyProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\File;
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
