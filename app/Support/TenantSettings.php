<?php

namespace App\Support;

use App\Models\Company;
use App\Models\Setting;
use Throwable;

/**
 * Per-tenant settings with graceful fallback to the application config
 * defaults. Values live in the current tenant's `settings` table; when a key
 * is not overridden for the tenant (or no tenant is active, e.g. in single-DB
 * tests) the matching `config()` value is returned instead.
 */
class TenantSettings
{
    /** @var array<int|string, array<string, mixed>> */
    protected array $cache = [];

    public function get(string $key, mixed $default = null): mixed
    {
        $overrides = $this->overrides();

        if (array_key_exists($key, $overrides)) {
            return $overrides[$key];
        }

        return config($key, $default);
    }

    public function set(string $key, mixed $value): void
    {
        Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        $this->flush();
    }

    public function flush(): void
    {
        $this->cache = [];
    }

    /**
     * @return array<string, mixed>
     */
    protected function overrides(): array
    {
        $tenantKey = Company::current()?->getKey() ?? 'default';

        if (! array_key_exists($tenantKey, $this->cache)) {
            $this->cache[$tenantKey] = $this->load();
        }

        return $this->cache[$tenantKey];
    }

    /**
     * @return array<string, mixed>
     */
    protected function load(): array
    {
        try {
            return Setting::all()->pluck('value', 'key')->all();
        } catch (Throwable) {
            // Settings table not yet available (e.g. tenant not provisioned).
            return [];
        }
    }
}
