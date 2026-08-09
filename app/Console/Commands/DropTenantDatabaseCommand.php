<?php

namespace App\Console\Commands;

use App\Modules\Core\Models\Company;
use App\Multitenancy\CompanyProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Drops a tenant database, and optionally its landlord company row.
 *
 * Exists for one recurring situation: provisioning is several non-transactional
 * steps, and rebuilding the landlord with `migrate:fresh` does not touch the
 * separate `tenant_*` schemas. Either can leave a database with no company
 * pointing at it, which {@see CompanyProvisioner} then refuses to provision over.
 *
 * Destructive and deliberately explicit — it never runs as a side effect of
 * provisioning, and prompts unless --force is given.
 */
class DropTenantDatabaseCommand extends Command
{
    protected $signature = 'tenant:drop
        {tenant : Company slug, or the raw tenant database name}
        {--keep-company : Drop the database but leave the landlord company row}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Drop a tenant database (and its company row), e.g. one left behind by a failed provision.';

    public function handle(): int
    {
        $tenant = $this->argument('tenant');

        $company = Company::where('slug', $tenant)->orWhere('database', $tenant)->first();
        $database = $company->database ?? $tenant;

        $connection = config('multitenancy.tenant_database_connection_name');

        if (! $connection) {
            $this->error('No tenant database connection is configured.');

            return self::FAILURE;
        }

        $isSqlite = config("database.connections.{$connection}.driver") === 'sqlite';

        if (! $this->databaseExists($database, $isSqlite)) {
            $this->warn("No tenant database [{$database}] found — nothing to drop.");

            if ($company && ! $this->option('keep-company')) {
                $this->removeCompany($company);
                $this->info("Removed the orphan company row [{$company->slug}].");
            }

            return self::SUCCESS;
        }

        $this->line("About to drop tenant database: <fg=yellow>{$database}</>");
        $this->line($company
            ? "Company row: <fg=yellow>#{$company->id} {$company->name}</>".($this->option('keep-company') ? ' (kept)' : ' (will also be removed)')
            : 'Company row: none (orphan database)');

        if (! $this->option('force') && ! $this->confirm('This permanently deletes the data. Continue?', false)) {
            $this->info('Aborted.');

            return self::SUCCESS;
        }

        DB::purge($connection);

        if ($isSqlite) {
            File::delete($database);
        } else {
            DB::connection(config('database.default'))->statement("DROP DATABASE IF EXISTS `{$database}`");
        }

        $this->info("Dropped tenant database [{$database}].");

        if ($company && ! $this->option('keep-company')) {
            $this->removeCompany($company);
            $this->info("Removed company row [{$company->slug}].");
        }

        return self::SUCCESS;
    }

    protected function databaseExists(string $database, bool $isSqlite): bool
    {
        if ($isSqlite) {
            return File::exists($database);
        }

        return DB::connection(config('database.default'))->selectOne(
            'select 1 as found from information_schema.schemata where schema_name = ?',
            [$database]
        ) !== null;
    }

    protected function removeCompany(Company $company): void
    {
        $company->users()->detach();
        $company->delete();
    }
}
