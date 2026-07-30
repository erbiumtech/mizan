<?php

namespace App\Console\Concerns;

use App\Modules\Core\Models\Company;
use App\Support\Modules;

/**
 * Scheduled work is the enforcement point that has no UI to hide behind: a
 * company with Projects switched off must not have its environments polled, its
 * certificates read, or incident rows written into its database every minute.
 *
 * These commands are TenantAware, so handle() runs once per company with that
 * company current — which is exactly why the module state lives in the landlord
 * database. There is no Filament tenant here, so Modules::enabled() falls back to
 * spatie's current tenant, which TenantAware has already set.
 */
trait SkipsDisabledModules
{
    protected function skipsDisabledModule(string $module): bool
    {
        if (modules()->enabled($module)) {
            return false;
        }

        $company = Company::current()?->name ?? 'unknown company';

        $this->line("<fg=gray>Skipping {$company}:</> ".Modules::label($module).' is not enabled.');

        return true;
    }
}
