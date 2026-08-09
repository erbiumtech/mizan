<?php

namespace App\Modules\Projects\Jobs;

use App\Modules\Projects\Models\ProjectEnvironment;
use App\Modules\Projects\Services\EnvironmentCertificateChecker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Spatie\Multitenancy\Jobs\TenantAware;

class CheckEnvironmentCertificate implements ShouldQueue, TenantAware
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public ProjectEnvironment $environment) {}

    public function handle(EnvironmentCertificateChecker $checker): void
    {
        if (! $this->environment->isHttps()) {
            return;
        }

        $checker->check($this->environment);
    }
}
