<?php

namespace App\Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * One hand-written value on the public site — docs/company-website-plan.md W1.
 *
 * Landlord: the site belongs to the installation. Read through `get()`, which caches the whole
 * table for the request's lifetime and beyond, because the layout asks for the tagline and the
 * footer on every public page and a query per key per page is the wrong price for five strings.
 */
class SiteSetting extends Model
{
    public const CACHE_KEY = 'site.settings';

    /** The keys the settings page edits, with the text shown when nothing has been typed. */
    public const DEFAULTS = [
        'tagline' => 'Payroll, people and books for Pakistani companies.',
        'intro' => 'Employee records, leave and attendance, payslips with the FBR and EOBI files, and a double-entry ledger — one product, priced per employee, with a trial that needs no card.',
        'contact_email' => null,
        'contact_phone' => null,
        'address' => null,
        'footer' => null,
    ];

    protected $fillable = ['key', 'value'];

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = static::values()[$key] ?? null;

        if ($value === null || $value === '') {
            return $default ?? static::DEFAULTS[$key] ?? null;
        }

        return $value;
    }

    public static function put(string $key, ?string $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget(static::CACHE_KEY);
    }

    /** @return array<string, string|null> */
    public static function values(): array
    {
        return Cache::rememberForever(static::CACHE_KEY, fn (): array => static::query()->pluck('value', 'key')->all());
    }

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget(static::CACHE_KEY));
        static::deleted(fn () => Cache::forget(static::CACHE_KEY));
    }
}
