<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * `tenant:drop` clears a tenant database left behind by a failed provision or
 * by rebuilding the landlord, which the provisioner otherwise refuses to
 * provision over.
 */
class DropTenantDatabaseCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['multitenancy.tenant_database_connection_name' => 'tenant']);
        File::ensureDirectoryExists(database_path('tenants'));
    }

    private function stubDatabase(string $slug): string
    {
        $path = database_path("tenants/{$slug}.sqlite");
        File::put($path, 'stale');

        return $path;
    }

    public function test_it_drops_the_database_and_the_company_row(): void
    {
        $company = Company::factory()->create(['slug' => 'acme-co']);
        $path = $this->stubDatabase('acme-co');
        $company->update(['database' => $path]);

        $this->artisan('tenant:drop', ['tenant' => 'acme-co', '--force' => true])
            ->assertSuccessful();

        $this->assertFalse(File::exists($path));
        $this->assertDatabaseMissing('companies', ['id' => $company->id]);
    }

    public function test_keep_company_leaves_the_landlord_row(): void
    {
        $company = Company::factory()->create(['slug' => 'keep-co']);
        $path = $this->stubDatabase('keep-co');
        $company->update(['database' => $path]);

        $this->artisan('tenant:drop', ['tenant' => 'keep-co', '--force' => true, '--keep-company' => true])
            ->assertSuccessful();

        $this->assertFalse(File::exists($path));
        $this->assertDatabaseHas('companies', ['id' => $company->id]);
    }

    /** The case that blocks provisioning: a schema with no company pointing at it. */
    public function test_it_drops_an_orphan_database_with_no_company_row(): void
    {
        $path = $this->stubDatabase('orphan-co');

        $this->artisan('tenant:drop', ['tenant' => $path, '--force' => true])
            ->assertSuccessful();

        $this->assertFalse(File::exists($path));
    }

    public function test_it_removes_an_orphan_company_row_when_the_database_is_already_gone(): void
    {
        $company = Company::factory()->create([
            'slug' => 'gone-co',
            'database' => database_path('tenants/gone-co.sqlite'),
        ]);

        $this->artisan('tenant:drop', ['tenant' => 'gone-co', '--force' => true])
            ->assertSuccessful();

        $this->assertDatabaseMissing('companies', ['id' => $company->id]);
    }

    public function test_declining_the_prompt_changes_nothing(): void
    {
        $company = Company::factory()->create(['slug' => 'safe-co']);
        $path = $this->stubDatabase('safe-co');
        $company->update(['database' => $path]);

        $this->artisan('tenant:drop', ['tenant' => 'safe-co'])
            ->expectsConfirmation('This permanently deletes the data. Continue?', 'no')
            ->assertSuccessful();

        $this->assertTrue(File::exists($path));
        $this->assertDatabaseHas('companies', ['id' => $company->id]);

        File::delete($path);
    }
}
