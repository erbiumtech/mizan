<?php

namespace App\Console\Commands;

use App\Services\HealthCheckDispatcher;
use Illuminate\Console\Command;
use Spatie\Multitenancy\Commands\Concerns\TenantAware;

/**
 * Daily TLS expiry sweep. Separate from the health check because certificates
 * don't change every five minutes and a handshake costs more than a HEAD.
 */
class CheckEnvironmentCertificates extends Command
{
    use TenantAware;

    protected $signature = 'projects:check-certificates {--tenant=*}';

    protected $description = 'Read TLS certificate expiry for https project environments';

    public function handle(HealthCheckDispatcher $dispatcher): int
    {
        $count = $dispatcher->dispatchCertificateChecks();

        $this->info("Dispatched {$count} certificate check(s).");

        return self::SUCCESS;
    }
}
