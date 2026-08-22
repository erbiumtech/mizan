<?php

namespace App\Support;

use App\Modules\Core\Models\Company;
use Database\Seeders\PersonalBaselineSeeder;
use Database\Seeders\TenantBaselineSeeder;

/**
 * What kind of business a company is, and what follows from that.
 *
 * A profile answers two questions at provisioning — which modules to license and
 * which baseline seeders to run — and one question for ever after: which modules
 * are *recommended* for this company, which is how the licensing screen sorts
 * itself. It is never an enforcement boundary; see config/company_profiles.php.
 *
 * Every method takes a nullable profile, and null is not an error: no company
 * created before this feature has one, and the answers below fall back to
 * exactly what those companies got. That fallback is the whole compatibility
 * story — an unset profile means "licensed and seeded by hand", which is true.
 */
class CompanyProfiles
{
    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        return config('company_profiles', []);
    }

    /** @return array<int, string> */
    public static function names(): array
    {
        return array_keys(static::all());
    }

    public static function has(?string $profile): bool
    {
        return $profile !== null && array_key_exists($profile, static::all());
    }

    /** @return array<string, mixed>|null */
    public static function get(?string $profile): ?array
    {
        return static::all()[$profile] ?? null;
    }

    public static function label(?string $profile): string
    {
        return static::get($profile)['label'] ?? 'Unassigned';
    }

    public static function description(?string $profile): string
    {
        return static::get($profile)['description'] ?? '';
    }

    /**
     * The company type a profile belongs to. Defaults to business rather than
     * throwing: a profile key that survived a config edit should mis-sort a
     * dropdown, not take the create page down.
     */
    public static function typeFor(?string $profile): string
    {
        return static::get($profile)['type'] ?? Company::TYPE_BUSINESS;
    }

    /**
     * Modules to license, or null to mean "use the module registry's own
     * defaults" — which is what Modules::seedDefaults() already understands and
     * what a company with no profile has always had.
     *
     * @return array<int, string>|null
     */
    public static function modules(?string $profile): ?array
    {
        $modules = static::get($profile)['modules'] ?? null;

        return $modules === null ? null : array_values($modules);
    }

    /**
     * Baseline seeders to run, in order.
     *
     * With no profile this is the type-based pair the provisioner has always
     * used, so an unprofiled company is seeded byte-for-byte as before. Callers
     * pass the type for exactly that case.
     *
     * @return array<int, class-string>
     */
    public static function seeders(?string $profile, string $type = Company::TYPE_BUSINESS): array
    {
        $seeders = static::get($profile)['seeders'] ?? null;

        if ($seeders !== null) {
            return array_values($seeders);
        }

        return $type === Company::TYPE_PERSONAL
            ? PersonalBaselineSeeder::seeders()
            : TenantBaselineSeeder::seeders();
    }

    /**
     * Profiles offered for a company type, as Filament select options.
     *
     * Filtered rather than merely sorted: offering "Trading / Distribution" for
     * a household would seed a business chart into a personal account, which is
     * the one combination the type exists to prevent.
     *
     * @return array<string, string>
     */
    public static function optionsForType(string $type): array
    {
        $options = [];

        foreach (static::all() as $profile => $definition) {
            if (($definition['type'] ?? Company::TYPE_BUSINESS) === $type) {
                $options[$profile] = $definition['label'] ?? $profile;
            }
        }

        return $options;
    }

    /**
     * The profile to preselect for a type. The only personal one for a personal
     * account; nothing for a business, because guessing "Services" for every
     * company would be worse than asking.
     */
    public static function defaultForType(string $type): ?string
    {
        if ($type !== Company::TYPE_PERSONAL) {
            return null;
        }

        return array_key_first(static::optionsForType($type));
    }

    /**
     * Is this module part of what the profile recommends? Used to group the
     * licensing screen, never to deny anything.
     */
    public static function recommends(?string $profile, string $module): bool
    {
        return in_array($module, static::modules($profile) ?? [], true);
    }
}
