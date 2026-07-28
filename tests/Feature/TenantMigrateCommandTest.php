<?php

namespace Tests\Feature;

use App\Support\TenantMigrations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Tests\TestCase;

/**
 * Guards the two silent failure modes of applying tenant migrations by hand:
 * the wrong path (nothing happens) and the wrong connection (the landlord
 * database gets the tenant schema).
 */
class TenantMigrateCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_command_is_registered(): void
    {
        $this->assertArrayHasKey('tenants:migrate', Artisan::all());
    }

    public function test_the_parameters_always_carry_the_tenant_path_and_connection(): void
    {
        config(['multitenancy.tenant_database_connection_name' => 'tenant']);

        $parameters = TenantMigrations::parameters();

        $this->assertSame('database/migrations/tenant', $parameters['--path']);
        $this->assertSame('tenant', $parameters['--database']);
        $this->assertTrue($parameters['--force']);
        $this->assertArrayNotHasKey('--pretend', $parameters);

        $this->assertTrue(TenantMigrations::parameters(pretend: true)['--pretend']);
    }

    public function test_it_refuses_to_run_when_no_dedicated_tenant_connection_is_configured(): void
    {
        // This is the test suite's own setup: a single database. Migrating the
        // tenant path here would add tenant tables to the landlord schema.
        config(['multitenancy.tenant_database_connection_name' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('landlord database');

        TenantMigrations::connection();
    }

    public function test_it_refuses_when_the_tenant_connection_is_the_default_one(): void
    {
        // Sharing one connection between landlord and tenants is the dangerous
        // case: the tenant schema would land in the landlord database.
        config(['multitenancy.tenant_database_connection_name' => config('database.default')]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('landlord database');

        TenantMigrations::connection();
    }

    /**
     * The command itself is not driven here: Spatie's TenantAware trait iterates
     * real per-tenant database connections, which the single-database suite
     * cannot provide. What is testable — and what actually goes wrong by hand —
     * is the path/connection resolution above; the command only catches the
     * RuntimeException and returns FAILURE.
     */
    public function test_the_command_declares_the_expected_options(): void
    {
        $definition = Artisan::all()['tenants:migrate']->getDefinition();

        $this->assertTrue($definition->hasOption('tenant'));
        $this->assertTrue($definition->hasOption('status'));
        $this->assertTrue($definition->hasOption('pretend'));
    }
}
