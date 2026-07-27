<?php

namespace App\Services;

use App\Jobs\CheckEnvironmentCertificate;
use App\Jobs\CheckEnvironmentHealth;
use App\Models\ProjectEnvironment;

/**
 * Picks the environments that are due and queues one job each.
 *
 * Kept out of the console commands so it can be tested without Spatie's
 * TenantAware wrapper, which needs a real per-tenant database connection and so
 * cannot run in the single-database test suite.
 */
class HealthCheckDispatcher
{
    /** @return int number of jobs queued */
    public function dispatchDueHealthChecks(): int
    {
        if (! config('projects.health.enabled', true)) {
            return 0;
        }

        $due = ProjectEnvironment::dueForCheck();

        foreach ($due as $environment) {
            CheckEnvironmentHealth::dispatch($environment);
        }

        return $due->count();
    }

    /** @return int number of jobs queued */
    public function dispatchCertificateChecks(): int
    {
        if (! config('projects.ssl.enabled', true)) {
            return 0;
        }

        $environments = ProjectEnvironment::query()
            ->monitorable()
            ->get()
            ->filter(fn (ProjectEnvironment $environment) => $environment->isHttps());

        foreach ($environments as $environment) {
            CheckEnvironmentCertificate::dispatch($environment);
        }

        return $environments->count();
    }
}
