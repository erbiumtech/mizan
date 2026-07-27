<?php

namespace App\Jobs;

use App\Models\ProjectEnvironment;
use App\Services\EnvironmentHealthChecker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Spatie\Multitenancy\Jobs\TenantAware;

/**
 * One job per environment, not one per batch: a single hanging host must not
 * stall every other project's checks. TenantAware makes the worker re-bind the
 * right company connection before the model is resolved.
 */
class CheckEnvironmentHealth implements ShouldQueue, TenantAware
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public ProjectEnvironment $environment) {}

    public function handle(EnvironmentHealthChecker $checker): void
    {
        if (! $this->environment->isMonitorable()) {
            return;
        }

        $checker->check($this->environment);
    }

    /** Keeps one slow environment from being retried into a pile-up. */
    public function uniqueId(): string
    {
        return 'environment-health-'.$this->environment->getKey();
    }
}
