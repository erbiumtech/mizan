<?php

namespace App\Modules\Projects\Console\Commands;

use App\Console\Concerns\SkipsDisabledModules;
use App\Modules\Projects\Services\HealthCheckDispatcher;
use Illuminate\Console\Command;
use Spatie\Multitenancy\Commands\Concerns\TenantAware;

/**
 * Daily TLS expiry sweep. Separate from the health check because certificates
 * don't change every five minutes and a handshake costs more than a HEAD.
 */
class CheckEnvironmentCertificates extends Command
{
    use SkipsDisabledModules;
    use TenantAware;

    protected $signature = 'projects:check-certificates {--tenant=*}';

    protected $description = 'Read TLS certificate expiry for https project environments';

    public function handle(HealthCheckDispatcher $dispatcher): int
    {
        if ($this->skipsDisabledModule('projects')) {
            return self::SUCCESS;
        }

        $count = $dispatcher->dispatchCertificateChecks();

        $this->info("Dispatched {$count} certificate check(s).");

        return self::SUCCESS;
    }
}
