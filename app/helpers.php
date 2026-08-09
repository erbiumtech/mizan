<?php

use App\Support\Modules;
use App\Support\TenantSettings;

if (! function_exists('modules')) {
    /**
     * The module state resolver (singleton, one landlord query per company per
     * request). `modules()->enabled('accounting')` is the question almost every
     * caller wants; enabledFor($companyId, …) is the one for commands and jobs.
     */
    function modules(): Modules
    {
        return app(Modules::class);
    }
}

if (! function_exists('setting')) {
    /**
     * Read a per-tenant setting, falling back to the application config default
     * when the current tenant has no override (or no tenant is active).
     */
    function setting(string $key, mixed $default = null): mixed
    {
        return app(TenantSettings::class)->get($key, $default);
    }
}
