<?php

namespace App\Support\Reporting;

use Closure;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;

/**
 * A short cache for the dashboard's expensive figures — `docs/reports-expansion-plan.md` Phase 5.8.
 *
 * "A cache for the expensive ones, with the tenant in the key. `docs/page-load-performance-plan.md` is
 * explicit about the failure mode here — caching across requests without the tenant in the key is a
 * cross-tenant leak — and a five-minute TTL on a twelve-month revenue series is the difference between a
 * dashboard and a report that runs fifteen times a day per user."
 *
 * **The cache lives in the widget and never in the service, and that rule is what keeps Phase 5 honest.**
 * Phase 5's whole premise is that a widget and its report read one service; if the cache went into the
 * service, the *report* would be reading a five-minute-old figure too — and a report that is quietly stale is
 * worse than a slow one, because its whole claim is that the rows add up to the total. So the service stays
 * exact, the report stays exact, and only the dashboard is allowed to be behind.
 *
 * **The company and the user are both in the key, and neither is optional.** The company because
 * `page-load-performance-plan.md` names the leak. The user because several of these widgets are scoped to what
 * that person may see, and one of them is scoped to their own work — a key without the user hands the next
 * caller somebody else's figures, which is a data leak rather than a wrong number.
 *
 * **In the key itself rather than left to the cache prefix**, for the reason `NavigationBadge` gives at
 * length: spatie's `PrefixCacheTask` does prefix per company while a tenant is current, but that is a
 * property of the *store*, and the array store the test suite runs on ignores prefixes entirely. A guard that
 * only works on one store is not a guard.
 *
 * **The company comes from `Filament::getTenant()`, not `Company::current()`, and that distinction cost a
 * debugging session.** This application has two notions of a current company: spatie's, which its middleware
 * makes current in a real request, and Filament's, which the panel sets. They do not agree in the test suite —
 * `InteractsWithTenant` sets Filament's and leaves spatie's null — so a cache keyed on `Company::current()`
 * silently never caches under test *and* keys every company under the same "nobody" in any code path that has
 * one but not the other. `NavigationBadge` reads `Filament::getTenant()` for exactly this reason, and this
 * follows it.
 *
 * **And the period is in the key**, which is a second kind of leak and easier to miss than the first: without
 * it, switching the dashboard from this month to the financial year would show the month's figures under the
 * year's heading for five minutes, and look like a reporting bug rather than a caching one.
 */
class DashboardCache
{
    /**
     * Five minutes, which is the plan's figure.
     *
     * Long enough that opening the dashboard four times in a morning costs one set of aggregates, short enough
     * that nobody makes a decision on a figure they cannot refresh within a coffee. There is deliberately no
     * invalidation on write: a dashboard figure that is five minutes behind is the trade this phase is making,
     * and hooking every posting path to clear it would be a great deal of coupling to save one stale figure.
     */
    public const TTL_SECONDS = 300;

    /**
     * The figure, cached — or computed fresh where there is nobody to key on.
     *
     * A console command or a queued job has no company and no user, and caching under "nobody" is exactly the
     * leak this class exists to prevent. `NavigationBadge` takes the same position for the same reason.
     *
     * `$parts` must carry everything the figure depends on beyond the company and the user — the period, an
     * account id, a window in days. It is the caller's list because only the caller knows; the company and the
     * user are added here because nobody should have to remember them.
     *
     * @param  array<string, mixed>  $parts
     */
    public static function remember(string $widget, array $parts, Closure $resolve): mixed
    {
        $company = Filament::getTenant()?->getKey();
        $user = auth()->id();

        if ($company === null || $user === null) {
            return $resolve();
        }

        return Cache::remember(
            self::key($widget, $company, $user, $parts),
            self::TTL_SECONDS,
            $resolve,
        );
    }

    /**
     * The key.
     *
     * The parts are sorted before hashing so `['period' => x, 'days' => y]` and `['days' => y, 'period' => x]`
     * are one key rather than two — a caller writing the same dependencies in a different order should not
     * halve the hit rate.
     *
     * `json_encode` rather than `serialize`: the parts are scalars and dates, and a serialised payload would
     * make the key depend on PHP's version rather than on the values.
     *
     * @param  array<string, mixed>  $parts
     */
    private static function key(string $widget, int|string $company, int|string $user, array $parts): string
    {
        ksort($parts);

        return 'dashboard:'.$company.':'.$user.':'.$widget.':'.md5((string) json_encode($parts));
    }

    /**
     * Forget one widget's cached figures for the signed-in user.
     *
     * Not called by anything yet, and here because a five-minute TTL with no way to clear it is the kind of
     * decision that is fine until somebody needs to demonstrate a change taking effect. Keyed the same way, so
     * it can only ever clear the caller's own.
     *
     * @param  array<string, mixed>  $parts
     */
    public static function forget(string $widget, array $parts): void
    {
        $company = Filament::getTenant()?->getKey();
        $user = auth()->id();

        if ($company === null || $user === null) {
            return;
        }

        Cache::forget(self::key($widget, $company, $user, $parts));
    }
}
