<?php

namespace App\Console\Commands;

use App\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the now-redundant domain tables from the LANDLORD database after the
 * data has been migrated into tenant databases (see tenancy:migrate-existing).
 * The landlord should only retain cross-company tables (users, companies,
 * permissions, activity_log, etc.). Each table is dropped only if the given
 * reference company's tenant DB actually contains that table — a safety guard
 * against dropping un-migrated data.
 */
class CleanupLandlordDomainTables extends Command
{
    protected $signature = 'tenancy:cleanup-landlord
        {--company=default : Reference company whose tenant DB must hold the data}
        {--force : Skip confirmation}';

    protected $description = 'Drop redundant domain tables from the landlord DB (data already in tenant DBs)';

    public function handle(): int
    {
        $tenantConnection = config('multitenancy.tenant_database_connection_name');
        if (! $tenantConnection || $tenantConnection === config('database.default')) {
            $this->error('A dedicated tenant database connection must be configured.');

            return self::FAILURE;
        }

        $company = Company::where('slug', $this->option('company'))->first();
        if (! $company) {
            $this->error("Reference company '{$this->option('company')}' not found.");

            return self::FAILURE;
        }

        $landlord = config('database.default');

        // Domain tables = tables that exist in the tenant DB (minus migrations).
        $company->makeCurrent();
        try {
            $domainTables = collect(Schema::connection($tenantConnection)->getTableListing())
                ->reject(fn ($t) => str_contains($t, '.') ? str_ends_with($t, '.migrations') : $t === 'migrations')
                ->map(fn ($t) => str_contains($t, '.') ? substr($t, strrpos($t, '.') + 1) : $t)
                ->values();

            $toDrop = [];
            foreach ($domainTables as $table) {
                // Only drop from landlord if it exists there AND in the tenant (data migrated).
                if (Schema::connection($landlord)->hasTable($table)
                    && Schema::connection($tenantConnection)->hasTable($table)) {
                    $toDrop[] = $table;
                }
            }
        } finally {
            Company::forgetCurrent();
        }

        if (! $toDrop) {
            $this->info('Nothing to drop — no migrated domain tables found in the landlord DB.');

            return self::SUCCESS;
        }

        $this->warn('The following tables will be DROPPED from the landlord DB ('.$landlord.'):');
        $this->line('  '.implode(', ', $toDrop));

        if (! $this->option('force') && ! $this->confirm('Proceed? Ensure tenant DBs are verified and backed up.')) {
            $this->info('Aborted.');

            return self::SUCCESS;
        }

        Schema::connection($landlord)->disableForeignKeyConstraints();
        try {
            foreach ($toDrop as $table) {
                Schema::connection($landlord)->dropIfExists($table);
                $this->line("  dropped {$table}");
            }
        } finally {
            Schema::connection($landlord)->enableForeignKeyConstraints();
        }

        $this->info('Landlord domain tables removed. '.count($toDrop).' table(s) dropped.');

        return self::SUCCESS;
    }
}
