<?php

namespace App\Support;

use App\Modules\Core\Models\Company;
use RuntimeException;

/**
 * What a customer pays for, and what that licenses — config/plans.php, read.
 *
 * The shape of CompanyProfiles on purpose: static, nullable everywhere, and null
 * is not an error. Every company that exists today has no plan, and for them
 * every answer below reproduces exactly what they have — licensed by hand,
 * billed by nobody. That fallback is the whole compatibility story.
 */
class Plans
{
    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        return config('plans.plans', []);
    }

    /** @return array<int, string> */
    public static function names(): array
    {
        return array_keys(static::all());
    }

    public static function has(?string $plan): bool
    {
        return $plan !== null && array_key_exists($plan, static::all());
    }

    /** @return array<string, mixed>|null */
    public static function get(?string $plan): ?array
    {
        return static::all()[$plan] ?? null;
    }

    public static function label(?string $plan): string
    {
        return static::get($plan)['label'] ?? 'No plan';
    }

    /**
     * The plan, or a refusal. Dropping an unknown key silently would license
     * the profile's whole list while the caller believed it had asked for a
     * tier — the same reasoning CompanyProvisioner gives for profiles.
     */
    public static function assertExists(?string $plan): string
    {
        if (! static::has($plan)) {
            throw new RuntimeException(
                "There is no plan [{$plan}]. Known plans: ".implode(', ', static::names()).'.'
            );
        }

        return $plan;
    }

    /**
     * Every module a plan sells — its own list plus everything the plan it
     * `includes` sells, recursively. Null for no plan, which Modules::seedDefaults()
     * reads as "the registry's own defaults", exactly as for no profile.
     *
     * @return array<int, string>|null
     */
    public static function modules(?string $plan): ?array
    {
        $definition = static::get($plan);

        if ($definition === null) {
            return null;
        }

        $modules = array_values($definition['modules'] ?? []);
        $seen = [$plan => true];
        $parent = $definition['includes'] ?? null;

        // A visited set rather than trusting the file: `includes` pointing at
        // itself would otherwise loop forever at boot.
        while ($parent !== null && ! isset($seen[$parent])) {
            $seen[$parent] = true;
            $modules = array_merge(static::get($parent)['modules'] ?? [], $modules);
            $parent = static::get($parent)['includes'] ?? null;
        }

        return array_values(array_unique($modules));
    }

    /**
     * What a company is licensed, given what it is and what it pays for.
     *
     * The base is the profile's list, or PERSONAL_DEFAULTS for a personal
     * account with no profile, or null — the three answers the provisioner has
     * always had. A plan intersects that base, or stands alone when there is no
     * base. Both inputs are closed under `requires` on their own (asserted by
     * CompanyProfileTest and PlanTest), and an intersection of two sets closed
     * under the same relation is closed too, so the result never licenses a
     * module whose requirement it withholds.
     *
     * @return array<int, string>|null null means "use the registry defaults"
     */
    public static function licencesFor(?string $profile, string $type, ?string $plan): ?array
    {
        $base = CompanyProfiles::modules($profile)
            ?? ($type === Company::TYPE_PERSONAL ? Modules::PERSONAL_DEFAULTS : null);

        if ($plan === null) {
            return $base;
        }

        $sold = static::modules($plan) ?? [];

        return $base === null
            ? $sold
            : array_values(array_intersect($base, $sold));
    }

    /** @return array<int, string> */
    public static function currencies(): array
    {
        return array_values(config('plans.currencies', []));
    }

    /**
     * Per active employee per month, for a plan; flat per month, for an add-on.
     * Refuses rather than returning zero: an invoice line at zero is a free
     * customer nobody decided to have.
     */
    public static function price(string $plan, string $currency): int|float
    {
        $prices = static::get($plan)['prices'] ?? static::addOns()[$plan]['prices'] ?? null;

        if ($prices === null || ! array_key_exists($currency, $prices)) {
            throw new RuntimeException("No [{$currency}] price for [{$plan}] in config/plans.php.");
        }

        return $prices[$currency];
    }

    public static function minimumSeats(): int
    {
        return (int) config('plans.minimum_seats', 1);
    }

    public static function trialPlan(): ?string
    {
        return config('plans.trial.plan');
    }

    public static function trialDays(): int
    {
        return (int) config('plans.trial.days', 0);
    }

    public static function allowsCustomDomain(?string $plan): bool
    {
        return (bool) (static::get($plan)['custom_domain'] ?? false);
    }

    /** @return array<string, array<string, mixed>> */
    public static function addOns(): array
    {
        return config('plans.add_ons', []);
    }

    /**
     * Plans as Filament select options, in the order the file lists them —
     * which is cheapest first.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (static::all() as $plan => $definition) {
            $options[$plan] = $definition['label'] ?? $plan;
        }

        return $options;
    }
}
