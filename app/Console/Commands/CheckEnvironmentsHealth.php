<?php

namespace App\Console\Commands;

use App\Services\HealthCheckDispatcher;
use Illuminate\Console\Command;
use Spatie\Multitenancy\Commands\Concerns\TenantAware;

/**
 * Dispatches a health check for every environment whose own interval says it is
 * due. Runs once a minute and selects what's due, rather than checking
 * everything on a fixed cadence, so per-environment intervals work.
 *
 * TenantAware: with no --tenant option it runs handle() once per company, with
 * that company's connection current.
 */
class CheckEnvironmentsHealth extends Command
{
    use TenantAware;

    protected $signature = 'projects:check-health {--tenant=*}';

    protected $description = 'Dispatch health checks for project environments that are due';

    public function handle(HealthCheckDispatcher $dispatcher): int
    {
        $count = $dispatcher->dispatchDueHealthChecks();

        $this->info("Dispatched {$count} health check(s).");

        return self::SUCCESS;
    }
}
