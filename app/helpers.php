<?php

use App\Support\TenantSettings;

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
