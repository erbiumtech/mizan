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
