<?php

use App\Support\Modules;
use App\Support\OptionLists;
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

if (! function_exists('options')) {
    /**
     * What an admin-managed dropdown offers, as value => label.
     *
     * `options('employees.designation', $record?->designation)` — the second argument is
     * the value already on the record, kept in the list even if it has since been
     * switched off, so editing an old row cannot blank a field nobody touched. See
     * App\Support\OptionLists.
     *
     * @return array<string, string>
     */
    function options(string $list, ?string $keep = null): array
    {
        return OptionLists::get($list, $keep);
    }
}
