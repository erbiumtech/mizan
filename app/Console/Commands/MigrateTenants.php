<?php

namespace App\Console\Commands;

use App\Modules\Core\Models\Company;
use App\Support\TenantMigrations;
use Illuminate\Console\Command;
use RuntimeException;
use Spatie\Multitenancy\Commands\Concerns\TenantAware;

/**
 * Applies the tenant migrations to every company (or just the ones named with
 * --tenant), with the path and connection filled in correctly.
 *
 * This exists because the hand-written equivalent is easy to get wrong:
 *
 *   php artisan tenants:artisan migrate            ← migrates the LANDLORD path,
 *                                                    prints "Nothing to migrate"
 *   php artisan tenants:artisan "migrate --path=…" ← applies the tenant schema
 *                                                    to the LANDLORD database
 *
 * Both failures are silent. Prefer `php artisan tenants:migrate`.
 */
class MigrateTenants extends Command
{
    use TenantAware;

    protected $signature = 'tenants:migrate
        {--tenant=* : Limit to these tenants (id, name or slug); defaults to all}
        {--status : Show what is pending instead of migrating}
        {--pretend : Print the SQL that would run, without running it}';

    protected $description = 'Run the tenant migrations for every company (correct path and connection)';

    public function handle(): int
    {
        try {
            $parameters = TenantMigrations::parameters(pretend: (bool) $this->option('pretend'));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $tenant = Company::current();
        $this->newLine();
        $this->line('<fg=gray>Tenant:</> '.($tenant?->name ?? 'unknown').' <fg=gray>→ '.($tenant?->database ?? '?').'</>');

        if ($this->option('status')) {
            // migrate:status takes the same path/connection but no --force/--pretend.
            return $this->call('migrate:status', [
                '--database' => $parameters['--database'],
                '--path' => $parameters['--path'],
            ]);
        }

        return $this->call('migrate', $parameters);
    }
}
