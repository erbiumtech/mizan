<?php

namespace App\Multitenancy;

use App\Models\Company;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\TenantBaselineSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PDO;
use RuntimeException;

/**
 * Provisions a brand-new company: creates the landlord record, creates and
 * migrates an isolated tenant database, seeds baseline reference data, and
 * attaches the creating user as an Administrator member.
 */
class CompanyProvisioner
{
    public function provision(string $name, ?string $slug = null, ?User $creator = null, bool $seedBaseline = true): Company
    {
        $connection = $this->tenantConnectionName();

        if (! $connection || $connection === config('database.default')) {
            throw new RuntimeException(
                'A dedicated tenant database connection must be configured before provisioning a company.'
            );
        }

        $this->assertConnectionCanMigrate($connection);

        $slug = $slug ?: $this->uniqueSlug($name);

        $company = Company::create([
            'name' => $name,
            'slug' => $slug,
            'database' => $this->databaseNameFor($slug),
            'status' => 1,
        ]);

        $this->createDatabase($company, $connection);

        $company->makeCurrent();

        try {
            Artisan::call('migrate', [
                '--database' => $connection,
                '--path' => 'database/migrations/tenant',
                '--force' => true,
            ]);

            if ($seedBaseline) {
                Artisan::call('db:seed', [
                    '--class' => TenantBaselineSeeder::class,
                    '--force' => true,
                ]);
            }

            // The tenant is current, so the permission team id is this company:
            // seed the company's own set of roles and attach the creator as its
            // Administrator — all scoped to this company's team.
            (new RoleSeeder)->run();

            if ($creator) {
                $this->attachCreator($company, $creator);
            }
        } finally {
            Company::forgetCurrent();
        }

        return $company;
    }

    /**
     * Laravel's SQLite schema builder introspects tables with the
     * pragma_table_xinfo() table-valued function, and rebuilds tables through
     * it whenever a migration changes a column or adds a foreign key. That
     * function needs SQLite >= 3.16 built with virtual table support; some
     * shared hosts ship PHP with an older or trimmed-down SQLite, where
     * migrating a tenant dies on a bare "near \"(\": syntax error".
     *
     * Fail up front with something actionable instead.
     */
    protected function assertConnectionCanMigrate(string $connection): void
    {
        if (config("database.connections.{$connection}.driver") !== 'sqlite') {
            return;
        }

        // Probed on a throwaway in-memory database: the capability belongs to
        // the pdo_sqlite build, not to the tenant's file (which does not exist
        // yet at this point).
        try {
            $probe = new PDO('sqlite::memory:');
            $version = (string) $probe->query('select sqlite_version()')->fetchColumn();
            $probe->query('select 1 from pragma_table_xinfo(\'sqlite_master\', \'main\') limit 1');
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'This server\'s SQLite build cannot run the tenant migrations: it has no '
                .'pragma_table_xinfo() table-valued function (needs SQLite >= 3.16 built with '
                .'virtual tables; this one reports '.($version ?? 'unknown').'). '
                .'Point tenant databases at MySQL instead by setting TENANT_DB_DRIVER=mysql '
                .'plus TENANT_DB_HOST / TENANT_DB_USERNAME / TENANT_DB_PASSWORD in .env.',
                previous: $e,
            );
        }
    }

    protected function attachCreator(Company $company, User $creator): void
    {
        if (! $company->users()->where('users.id', $creator->getKey())->exists()) {
            $company->users()->attach($creator->getKey());
        }

        $creator->assignRole('Administrator');
    }

    protected function createDatabase(Company $company, string $connection): void
    {
        $driver = config("database.connections.{$connection}.driver");

        if ($driver === 'sqlite') {
            File::ensureDirectoryExists(dirname($company->database));

            if (! File::exists($company->database)) {
                touch($company->database);
            }

            return;
        }

        // MySQL / PostgreSQL: create the schema on the landlord connection.
        DB::connection(config('database.default'))->statement(
            "CREATE DATABASE IF NOT EXISTS `{$company->database}`"
        );
    }

    protected function databaseNameFor(string $slug): string
    {
        $connection = $this->tenantConnectionName();

        if (config("database.connections.{$connection}.driver") === 'sqlite') {
            return database_path("tenants/{$slug}.sqlite");
        }

        return 'tenant_'.str_replace('-', '_', $slug);
    }

    protected function uniqueSlug(string $name): string
    {
        do {
            $slug = Str::slug($name).'-'.Str::lower(Str::random(4));
        } while (Company::where('slug', $slug)->exists());

        return $slug;
    }

    protected function tenantConnectionName(): ?string
    {
        return config('multitenancy.tenant_database_connection_name');
    }
}
