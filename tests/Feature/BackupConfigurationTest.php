<?php

namespace Tests\Feature;

use App\Backup\TenantBackup;
use App\Modules\Core\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Tests\TestCase;

/**
 * That the backup covers every database, not just the one the package defaults to.
 *
 * `spatie/laravel-backup` dumps a list of connection names, and its published default is one
 * entry: the default connection. In a database-per-tenant application that is the landlord —
 * `companies`, `users`, `roles`, the activity log — and **none of any company's accounting
 * data**.
 *
 * The reason this file exists rather than a comment in the config: that failure is silent. The
 * archive is produced, on schedule, at a plausible size, and nothing is wrong until somebody
 * tries to restore a company's books and finds they were never in there. So the shape of the
 * configuration is asserted, and so is the tenant path that covers the rest.
 */
class BackupConfigurationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The suite runs single-database — phpunit.xml sets TENANT_DATABASE_CONNECTION to
        // empty, so `multitenancy.tenant_database_connection_name` is null and there is no
        // tenant connection to copy. The tenant backup only exists when tenancy is configured
        // with separate databases, so the tests for it configure that, and the refusal when it
        // is missing is asserted on its own below.
        config(['multitenancy.tenant_database_connection_name' => 'tenant']);
    }

    // ----------------------------------------------------------- the landlord

    /**
     * The list stays the landlord alone.
     *
     * Two wrong values this catches. Adding `tenant` looks like the fix and is not: it is one
     * connection name repointed per company, so it would dump whichever company happened to be
     * current. And `backup_tenant` is the throwaway connection `TenantBackup` repoints — if it
     * leaked into the landlord config, `backup:run` would dump a tenant instead of the landlord.
     */
    public function test_the_landlord_backup_names_the_landlord_connection_only(): void
    {
        $databases = config('backup.backup.source.databases');

        $this->assertSame([config('database.default')], $databases);

        $this->assertNotContains(
            config('multitenancy.tenant_database_connection_name'),
            $databases,
            'the tenant connection is one name pointed at a different database per company, so '
            .'backing it up here dumps one unpredictable company',
        );

        $this->assertNotContains(TenantBackup::CONNECTION, $databases);
    }

    /**
     * The uploads are what a backup is for; the code is in git.
     *
     * `storage/app` covers both disks people actually upload to — `app/public` (receipts,
     * CVs, employee documents) and `app/private` (attendance imports).
     */
    public function test_the_backup_includes_the_uploads(): void
    {
        $include = config('backup.backup.source.files.include');

        $this->assertContains(storage_path('app'), $include);
    }

    /**
     * `.env` is deliberately not in the archive — it would put `APP_KEY` and the database
     * password on whatever disk the archives are shipped to. Asserted so that a later "let's
     * back up everything" does not quietly turn every archive into a credential dump.
     */
    public function test_the_backup_does_not_include_the_application_root_or_the_env_file(): void
    {
        $include = config('backup.backup.source.files.include');

        $this->assertNotContains(base_path(), $include);
        $this->assertNotContains(base_path('.env'), $include);
    }

    /** So an operator reading a directory listing can tell the two kinds of archive apart. */
    public function test_landlord_archives_are_named_as_such(): void
    {
        $this->assertSame('landlord-', config('backup.backup.destination.filename_prefix'));
    }

    // ------------------------------------------------------------- the tenants

    public function test_a_company_gets_a_connection_pointed_at_its_own_database(): void
    {
        $company = Company::factory()->create(['database' => 'tenant_alpha']);

        $connection = app(TenantBackup::class)->connectionFor($company);

        $this->assertSame(TenantBackup::CONNECTION, $connection);
        $this->assertSame('tenant_alpha', config("database.connections.{$connection}.database"));
    }

    /**
     * The connection is copied from the tenant connection, not hand-built, so credentials and
     * options follow whatever multitenancy already uses. A hand-built one drifts the day
     * somebody adds an SSL option to the tenant connection and not to this.
     */
    public function test_the_backup_connection_inherits_the_tenant_connection_settings(): void
    {
        $template = config('multitenancy.tenant_database_connection_name');
        config(["database.connections.{$template}.some_option" => 'inherited']);

        $company = Company::factory()->create(['database' => 'tenant_alpha']);

        $connection = app(TenantBackup::class)->connectionFor($company);

        $this->assertSame('inherited', config("database.connections.{$connection}.some_option"));
        $this->assertSame(
            config("database.connections.{$template}.driver"),
            config("database.connections.{$connection}.driver"),
        );
    }

    /**
     * Repointing between companies must not keep the previous handle — that would dump the
     * previous company's database into this company's archive, which is a wrong backup and a
     * leak between tenants at the same time.
     */
    public function test_backing_up_a_second_company_repoints_the_connection(): void
    {
        $backup = app(TenantBackup::class);

        $first = Company::factory()->create(['database' => 'tenant_alpha']);
        $second = Company::factory()->create(['database' => 'tenant_beta']);

        $backup->connectionFor($first);
        $this->assertSame('tenant_alpha', config('database.connections.'.TenantBackup::CONNECTION.'.database'));

        $backup->connectionFor($second);
        $this->assertSame('tenant_beta', config('database.connections.'.TenantBackup::CONNECTION.'.database'));
    }

    /**
     * A company with a blank database name is a bug to surface, not a row to skip quietly.
     *
     * Blank rather than null: `companies.database` is NOT NULL, so null is unreachable — but
     * NOT NULL permits the empty string, which reaches exactly the same dead end. The command
     * reports it as a per-company failure and carries on with the rest.
     */
    public function test_a_company_with_a_blank_database_is_refused_rather_than_skipped(): void
    {
        $company = Company::factory()->create(['database' => '']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no database recorded/');

        app(TenantBackup::class)->connectionFor($company);
    }

    /**
     * Every company, unfiltered. The enumeration must not quietly drop the one company whose
     * backup most needs looking at.
     */
    public function test_every_company_is_enumerated(): void
    {
        Company::factory()->create(['database' => 'tenant_alpha']);
        Company::factory()->create(['database' => 'tenant_beta']);
        Company::factory()->create(['database' => '']);

        $companies = app(TenantBackup::class)->companies();

        $this->assertSame(['tenant_alpha', 'tenant_beta', ''], $companies->pluck('database')->all());
    }

    /**
     * Dormant companies included, deliberately. A quiet company's books are exactly as
     * required to exist as a busy one's.
     */
    public function test_a_suspended_company_is_still_backed_up(): void
    {
        Company::factory()->create(['database' => 'tenant_alpha', 'status' => 0]);

        $this->assertCount(1, app(TenantBackup::class)->companies());
    }

    /**
     * With no tenant connection configured there is nothing to copy, and saying so is better
     * than producing a connection pointed at the landlord and dumping it N times under N
     * company names.
     */
    public function test_it_refuses_when_no_tenant_connection_is_configured(): void
    {
        config(['multitenancy.tenant_database_connection_name' => null]);

        $company = Company::factory()->create(['database' => 'tenant_alpha']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/is not configured/');

        app(TenantBackup::class)->connectionFor($company);
    }

    // ------------------------------------------------------------- the command

    public function test_the_command_refuses_an_unknown_company_slug(): void
    {
        Company::factory()->create(['database' => 'tenant_alpha', 'slug' => 'alpha']);

        $this->artisan('backup:tenants', ['--company' => ['no-such-company']])
            ->expectsOutputToContain('No such company')
            ->assertExitCode(1);
    }

    /**
     * Tenant archives keep out of the landlord's monitored destination.
     *
     * `backup:monitor` lists with `allFiles()`, which recurses — so a tenant archive nested
     * under the landlord's backup name would satisfy the landlord's "newest backup is less than
     * a day old" health check. `backup:run` could then fail every night for a month while the
     * monitor stayed green on fresh tenant archives, which is worse than having no monitor.
     *
     * A sibling name is outside `allFiles({landlord name})`. This asserts it stays a sibling and
     * never becomes a child.
     */
    public function test_tenant_archives_go_to_their_own_destination_not_inside_the_landlords(): void
    {
        $company = Company::factory()->create(['database' => 'tenant_alpha', 'slug' => 'alpha']);

        $landlord = config('backup.backup.name');
        $destination = app(TenantBackup::class)->destinationFor($company);

        $this->assertNotSame($landlord, $destination['name']);
        $this->assertStringStartsNotWith($landlord.'/', $destination['name']);
        $this->assertStringContainsString('alpha', $destination['name']);
        $this->assertSame('tenant-alpha-', $destination['prefix']);
    }

    /** Two companies must not share a destination, or one overwrites the other's retention. */
    public function test_each_company_gets_its_own_destination(): void
    {
        $backup = app(TenantBackup::class);

        $first = Company::factory()->create(['database' => 'a', 'slug' => 'alpha']);
        $second = Company::factory()->create(['database' => 'b', 'slug' => 'beta']);

        $this->assertNotSame(
            $backup->destinationFor($first)['name'],
            $backup->destinationFor($second)['name'],
        );
    }

    /**
     * `--config=backup` is passed, and this is the regression guard for the bug that shipped
     * once already.
     *
     * The package binds its Config object with `app->scoped()`, built from config/backup.php the
     * first time anything resolves it and cached for the process. `BackupCommand` takes it by
     * constructor injection — so `run()`'s three config overrides were invisible to it, and
     * `backup:run` re-dumped the **landlord**, under the landlord's name and prefix, once per
     * company. Two archives, identical bytes, plausible timestamps, wrong database. Nothing
     * failed and nothing warned.
     *
     * `--config` makes the command re-read `config('backup')` at execution time. Asserted on the
     * call rather than the outcome because the outcome needs a live mysqldump; what broke was
     * the argument, so the argument is what is pinned.
     */
    public function test_the_tenant_backup_forces_the_command_to_reread_the_config(): void
    {
        $company = Company::factory()->create(['database' => 'tenant_alpha', 'slug' => 'alpha']);

        Artisan::shouldReceive('call')
            ->once()
            ->withArgs(function (string $command, array $parameters): bool {
                return $command === 'backup:run'
                    && ($parameters['--only-db'] ?? false) === true
                    && ($parameters['--config'] ?? null) === 'backup';
            })
            ->andReturn(0);

        $this->assertSame(0, app(TenantBackup::class)->run($company));
    }

    /**
     * The landlord configuration is restored even when a company's dump throws, so the next
     * `backup:run` cannot archive one company's books under the landlord's name.
     */
    public function test_a_failed_company_leaves_the_landlord_configuration_untouched(): void
    {
        $company = Company::factory()->create(['database' => 'tenant_alpha', 'slug' => 'alpha']);

        $name = config('backup.backup.name');
        $databases = config('backup.backup.source.databases');
        $prefix = config('backup.backup.destination.filename_prefix');

        Artisan::shouldReceive('call')->once()->andThrow(new RuntimeException('mysqldump exploded'));

        try {
            app(TenantBackup::class)->run($company);
            $this->fail('the failure was swallowed');
        } catch (RuntimeException) {
            // Expected — the command catches this per company; here we only care what it left.
        }

        $this->assertSame($name, config('backup.backup.name'));
        $this->assertSame($databases, config('backup.backup.source.databases'));
        $this->assertSame($prefix, config('backup.backup.destination.filename_prefix'));
    }

    /** Nothing to back up must not read as success. */
    public function test_the_command_says_an_empty_installation_is_not_a_pass(): void
    {
        $this->artisan('backup:tenants')
            ->expectsOutputToContain('not a pass')
            ->assertExitCode(0);
    }
}
