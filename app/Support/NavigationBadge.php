<?php

namespace App\Support;

use Closure;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/**
 * The counts in the sidebar, priced properly.
 *
 * Eleven resources put a number beside their name — pending leave, unapproved
 * attendance, claims waiting, documents about to expire. Filament evaluates
 * every one of them while it builds the navigation, and it evaluates them as
 * values rather than closures (`Page::getNavigationItems()` calls
 * `->badge(static::getNavigationBadge())`), so the count runs whether or not the
 * branch holding it is open, on every page of the panel. Measured before this
 * class existed: 11 of the dashboard's 25 statements were badge counts, and
 * several were not plain counts — the leave one filters through the manager
 * hierarchy.
 *
 * So they are cached for a minute. A pending-approvals figure up to sixty
 * seconds old is not a wrong number in any sense a person would notice — and on
 * the one screen where it would be, the resource's own index, the table beside
 * it shows the truth. Eleven counts on every page load is the larger
 * inaccuracy.
 *
 * Deliberately no invalidation on write. Eleven models, four modules and a
 * scattering of approval actions is a lot of surface to keep in step for a
 * saving the TTL already makes, and an invalidation hook that is only *mostly*
 * wired is worse than a rule everybody knows: the number is up to a minute old.
 *
 * See docs/page-load-performance-plan.md.
 */
class NavigationBadge
{
    /** How long a count stands. Short enough to feel live, long enough to matter. */
    public const TTL_SECONDS = 60;

    /**
     * Counts already resolved during this request.
     *
     * An instance property, and the class is bound `scoped` in AppServiceProvider
     * so the instance dies with the request. A static array here would outlive
     * it: the test suite runs many requests in one process and the second one
     * read the first one's figures — a different company, a different user —
     * which is the same leak a `singleton` would be in production.
     *
     * @var array<string, int>
     */
    private array $memo = [];

    /**
     * A resource's badge: the count, cached, rendered the way Filament wants it.
     *
     * Returns null at zero, which is what hides the badge — a grey `0` beside
     * every quiet resource is noise, and every caller here already did this.
     *
     * @param  class-string  $resource  the resource the count belongs to
     * @param  Closure(): int  $count  runs on a miss, and only then
     */
    public static function of(string $resource, Closure $count): ?string
    {
        $total = app(static::class)->remember($resource, $count);

        return $total > 0 ? (string) $total : null;
    }

    /**
     * @param  class-string  $resource
     * @param  Closure(): int  $count
     */
    public function remember(string $resource, Closure $count): int
    {
        $key = $this->key($resource);

        // No company or no user — the console, a queued job — is not a request
        // whose sidebar is being drawn, and caching under "nobody" would hand
        // the next caller somebody else's figure.
        if ($key === null) {
            return (int) $count();
        }

        return $this->memo[$key] ??= (int) Cache::remember(
            $key,
            static::TTL_SECONDS,
            fn (): int => (int) $count(),
        );
    }

    /**
     * The key, or null when there is nobody to key on.
     *
     * Both the company and the user are in the key itself rather than left to
     * the cache prefix. spatie's PrefixCacheTask does prefix per company while a
     * tenant is current, but that is a property of the *store* — the array store
     * the test suite runs on ignores prefixes entirely — and a badge that leaks
     * between companies is a data leak rather than a wrong number. The user is
     * in there because several of these counts are scoped to what that user may
     * see: LeaveRequestResource counts through `getEloquentQuery()`, which for a
     * non-privileged user is filtered to their own downline.
     *
     * @param  class-string  $resource
     */
    private function key(string $resource): ?string
    {
        $company = Filament::getTenant();
        $user = Auth::user();

        if ($company === null || $user === null) {
            return null;
        }

        return implode(':', [
            'nav-badge',
            $company->getKey(),
            $user->getAuthIdentifier(),
            $resource,
        ]);
    }
}
