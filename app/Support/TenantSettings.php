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

        if (! array_key_exists($key, $overrides)) {
            return config($key, $default);
        }

        $override = $overrides[$key];
        $configured = config($key, $default);

        // A map-shaped setting (payroll account codes, iPayments defaults) is
        // merged over its config defaults rather than replacing them outright.
        //
        // Company Settings saves the whole map in one go, so a partial or blank
        // save used to erase every key it did not carry — and because those
        // KeyValue fields are not addable, the keys could not be put back from
        // the page. Payroll posting then died on "account 'basic_wage' (code )
        // not found". Merging makes a missing or blank key fall back to config,
        // so the defaults are always the floor.
        if (static::isMap($override) && static::isMap($configured)) {
            return array_replace($configured, static::withoutBlanks($override));
        }

        return $override;
    }

    /**
     * An associative array — not a list, and not a scalar.
     *
     * An empty array counts: `array_is_list([])` is true, but a settings map
     * saved empty is a blank save, and treating it as a list would replace the
     * defaults with nothing, which is the bug this exists to prevent.
     */
    protected static function isMap(mixed $value): bool
    {
        return is_array($value) && ($value === [] || ! array_is_list($value));
    }

    /**
     * Drops keys whose value is blank, so an empty box in the settings UI reads
     * as "use the default" instead of overriding it with nothing.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    protected static function withoutBlanks(array $values): array
    {
        return array_filter(
            $values,
            fn ($value) => ! (is_null($value) || (is_string($value) && trim($value) === '')),
        );
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
