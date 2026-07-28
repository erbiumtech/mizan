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
        $database = $this->databaseNameFor($slug);

        // Checked before the Company row is written, so a refusal here leaves
        // nothing behind.
        $this->assertDatabaseIsUsable($database, $connection);

        $company = Company::create([
            'name' => $name,
            'slug' => $slug,
            'database' => $database,
            'status' => 1,
        ]);

        // Only tear down a database this call brought into existence.
        $createdDatabase = ! $this->databaseExists($database, $connection);

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
        } catch (\Throwable $e) {
            // Provisioning is several non-transactional steps (MySQL cannot roll
            // back DDL), so a failure half-way used to leave an orphan Company
            // row pointing at a half-migrated database. The next attempt then
            // adopted that database and died on whichever table the previous run
            // had already created. Undo our own work instead.
            Company::forgetCurrent();
            $this->rollBack($company, $connection, $createdDatabase);

            throw $e;
        } finally {
            Company::forgetCurrent();
        }

        return $company;
    }

    /**
     * Discard a partially provisioned company: the tenant database (only if
     * this run created it) and the landlord row.
     *
     * Best-effort — a failure here must not mask the error that caused it.
     */
    protected function rollBack(Company $company, string $connection, bool $dropDatabase): void
    {
        try {
            DB::purge($connection);

            if ($dropDatabase) {
                if (config("database.connections.{$connection}.driver") === 'sqlite') {
                    File::delete($company->database);
                } else {
                    DB::connection(config('database.default'))
                        ->statement("DROP DATABASE IF EXISTS `{$company->database}`");
                }
            }

            $company->users()->detach();
            $company->delete();
        } catch (\Throwable) {
            // Swallowed on purpose: the caller is about to see the real failure.
        }
    }

    /**
     * Refuse to provision on top of a tenant database that already has tables.
     *
     * `CREATE DATABASE IF NOT EXISTS` happily adopts an existing schema, so
     * without this a leftover database (from a failed run, or from rebuilding
     * the landlord with `migrate:fresh` while the tenant schemas survived) gets
     * reused. Its stale `migrations` table then makes `migrate` skip most of the
     * work and fail on the first table an earlier attempt had created.
     */
    protected function assertDatabaseIsUsable(string $database, string $connection): void
    {
        if (! $this->databaseExists($database, $connection) || $this->databaseIsEmpty($database, $connection)) {
            return;
        }

        throw new RuntimeException(
            "The tenant database [{$database}] already exists and contains tables, so it is not safe "
            .'to provision into. If it is left over from a failed attempt or from rebuilding the landlord, '
            ."clear it with:\n\n    php artisan tenant:drop {$database}\n\n"
            .'and try again. If it holds real data, provision under a different slug instead.'
        );
    }

    protected function databaseExists(string $database, string $connection): bool
    {
        if (config("database.connections.{$connection}.driver") === 'sqlite') {
            return File::exists($database);
        }

        return DB::connection(config('database.default'))->selectOne(
            'select 1 as found from information_schema.schemata where schema_name = ?',
            [$database]
        ) !== null;
    }

    protected function databaseIsEmpty(string $database, string $connection): bool
    {
        if (config("database.connections.{$connection}.driver") === 'sqlite') {
            return ! File::exists($database) || File::size($database) === 0;
        }

        $count = DB::connection(config('database.default'))->selectOne(
            'select count(*) as tables from information_schema.tables where table_schema = ?',
            [$database]
        );

        return (int) ($count->tables ?? 0) === 0;
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
