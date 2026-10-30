<?php

namespace App\Support;

use Closure;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The counts in the sidebar, priced properly.
 *
 * Twenty resources put a number beside their name — pending leave, unapproved attendance, claims
 * waiting, documents about to expire, unassigned work items. Filament evaluates every one of them
 * while it builds the navigation, and it evaluates them as values rather than closures
 * (`Page::getNavigationItems()` calls `->badge(static::getNavigationBadge())`), so the count runs
 * whether or not the branch holding it is open, on every page of the panel.
 *
 * Two things make that affordable, and the second is why this class was rewritten.
 *
 * **They are cached for a minute.** A pending-approvals figure up to sixty seconds old is not a
 * wrong number in any sense a person would notice — and on the one screen where it would be, the
 * resource's own index, the table beside it shows the truth.
 *
 * **And on a cold request they are now one round trip, not twenty.** The TTL only helps the second
 * visitor; the first one after a quiet minute paid for twenty separate `select count(*)` statements
 * against twenty tables on one connection. `PanelPerformanceTest` recorded that band growing four
 * times and asked twice, in as many words, for a registry that collected them. This is it: on the
 * first miss of a request, every resource that can express its count as a query is asked for one,
 * and they go to the database together as scalar subqueries of a single statement.
 *
 * A resource opts in by defining `badgeQuery(): ?Builder` — returning the query, not the count.
 * One that cannot (a figure computed in PHP, a count across two connections) keeps passing a
 * closure to `of()` and is simply not batched; both kinds are cached the same way.
 *
 * Deliberately no invalidation on write. Twenty models, ten modules and a scattering of approval
 * actions is a lot of surface to keep in step for a saving the TTL already makes, and an
 * invalidation hook that is only *mostly* wired is worse than a rule everybody knows: the number is
 * up to a minute old.
 *
 * See docs/page-load-performance-plan.md and docs/project-management-plan.md §14 Phase 0.
 */
class NavigationBadge
{
    /** How long a count stands. Short enough to feel live, long enough to matter. */
    public const TTL_SECONDS = 60;

    /**
     * Counts already resolved during this request.
     *
     * An instance property, and the class is bound `scoped` in AppServiceProvider so the instance
     * dies with the request. A static array here would outlive it: the test suite runs many requests
     * in one process and the second one read the first one's figures — a different company, a
     * different user — which is the same leak a `singleton` would be in production.
     *
     * @var array<string, int>
     */
    private array $memo = [];

    /** Whether the one combined query has already run this request. */
    private bool $batched = false;

    /**
     * A resource's badge: the count, cached, rendered the way Filament wants it.
     *
     * Returns null at zero, which is what hides the badge — a grey `0` beside every quiet resource
     * is noise, and every caller here already did this.
     *
     * @param  class-string  $resource  the resource the count belongs to
     * @param  ?Closure(): int  $count  for a resource with no `badgeQuery()`; runs on a miss, and only then
     */
    public static function of(string $resource, ?Closure $count = null): ?string
    {
        $total = app(static::class)->remember($resource, $count);

        return $total > 0 ? (string) $total : null;
    }

    /**
     * @param  class-string  $resource
     * @param  ?Closure(): int  $count
     */
    public function remember(string $resource, ?Closure $count = null): int
    {
        $key = $this->key($resource);

        // No company or no user — the console, a queued job — is not a request whose sidebar is
        // being drawn, and caching under "nobody" would hand the next caller somebody else's figure.
        if ($key === null) {
            return $this->resolveAlone($resource, $count);
        }

        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        $cached = Cache::get($key);

        if ($cached !== null) {
            return $this->memo[$key] = (int) $cached;
        }

        /*
         * A caller that handed over a closure gets that closure, and the batch is not consulted.
         *
         * Only a resource that declares `badgeQuery()` can be batched, and one may do both — the
         * resource for its own sidebar badge, a caller asking the same class for a different figure.
         * The explicit argument is the specific instruction and wins: a batch that quietly answered
         * with the resource's own count instead would be returning the wrong number, which is worse
         * than returning it slowly.
         */
        if ($count === null) {
            // A miss, and every other badge on this page is about to miss too, so they go together.
            $this->fillBatch();

            if (array_key_exists($key, $this->memo)) {
                return $this->memo[$key];
            }
        }

        $total = $this->resolveAlone($resource, $count);
        Cache::put($key, $total, static::TTL_SECONDS);

        return $this->memo[$key] = $total;
    }

    /**
     * Every uncached badge on the panel, in one statement.
     *
     * The shape is `select (select count(*) from a where ...) as b0, (select count(*) from b ...) as
     * b1, ...` — one round trip, as many scalar subqueries as there are badges to fill. Grouped by
     * connection because a subquery cannot cross one, which in practice means one group.
     *
     * Everything here is best-effort by design: a resource that throws while being asked for its
     * query is skipped rather than allowed to take the sidebar down with it, and its own `of()` call
     * falls back to the closure path. A navigation that fails to draw is a worse outcome than a
     * badge that is missing.
     */
    private function fillBatch(): void
    {
        if ($this->batched) {
            return;
        }

        $this->batched = true;

        $panel = Filament::getCurrentPanel();

        if ($panel === null) {
            return;
        }

        /** @var array<string, array<string, Builder>> $pending */
        $pending = [];

        foreach ($panel->getResources() as $resource) {
            if (! method_exists($resource, 'badgeQuery')) {
                continue;
            }

            $key = $this->key($resource);

            if ($key === null || array_key_exists($key, $this->memo)) {
                continue;
            }

            $cached = Cache::get($key);

            if ($cached !== null) {
                $this->memo[$key] = (int) $cached;

                continue;
            }

            try {
                // The same gate Filament uses to decide whether to render the item at all, so a
                // disabled module's resource is never queried and a user is never counted something
                // they may not see.
                if (method_exists($resource, 'canAccess') && ! $resource::canAccess()) {
                    continue;
                }

                $query = $resource::badgeQuery();
            } catch (Throwable) {
                continue;
            }

            if ($query === null) {
                continue;
            }

            $pending[$query->getConnection()->getName()][$key] = $query;
        }

        foreach ($pending as $connection => $queries) {
            $this->runBatch($connection, $queries);
        }
    }

    /**
     * @param  array<string, Builder>  $queries
     */
    private function runBatch(string $connection, array $queries): void
    {
        $db = DB::connection($connection);
        $grammar = $db->getQueryGrammar();

        $selects = [];
        $bindings = [];
        $aliases = [];
        $index = 0;

        foreach ($queries as $key => $query) {
            $alias = 'b'.$index++;
            $aliases[$alias] = $key;

            // Columns, ordering and paging mean nothing inside `count(*)`, and an ORDER BY on a
            // scalar subquery is an error on some drivers rather than a no-op.
            $base = $query->toBase()
                ->cloneWithout(['columns', 'orders', 'limit', 'offset'])
                ->cloneWithoutBindings(['select', 'order']);

            $base->selectRaw('count(*)');

            $selects[] = '('.$base->toSql().') as '.$grammar->wrap($alias);
            $bindings = array_merge($bindings, $base->getBindings());
        }

        if ($selects === []) {
            return;
        }

        try {
            $row = (array) $db->selectOne('select '.implode(', ', $selects), $bindings);
        } catch (Throwable) {
            // One malformed subquery would otherwise cost every badge on the page. Leaving the memo
            // empty sends each back to its own closure, which is where they were before this class
            // learned to batch.
            return;
        }

        foreach ($aliases as $alias => $key) {
            $total = (int) ($row[$alias] ?? 0);

            Cache::put($key, $total, static::TTL_SECONDS);
            $this->memo[$key] = $total;
        }
    }

    /**
     * One badge, on its own — the path for a resource with no `badgeQuery()`, and the fallback for
     * one whose batch did not produce a figure.
     *
     * @param  class-string  $resource
     * @param  ?Closure(): int  $count
     */
    private function resolveAlone(string $resource, ?Closure $count): int
    {
        if ($count !== null) {
            return (int) $count();
        }

        if (method_exists($resource, 'badgeQuery')) {
            try {
                return (int) ($resource::badgeQuery()?->count() ?? 0);
            } catch (Throwable) {
                return 0;
            }
        }

        return 0;
    }

    /**
     * The key, or null when there is nobody to key on.
     *
     * Both the company and the user are in the key itself rather than left to the cache prefix.
     * spatie's PrefixCacheTask does prefix per company while a tenant is current, but that is a
     * property of the *store* — the array store the test suite runs on ignores prefixes entirely —
     * and a badge that leaks between companies is a data leak rather than a wrong number. The user
     * is in there because several of these counts are scoped to what that user may see:
     * LeaveRequestResource counts through `getEloquentQuery()`, which for a non-privileged user is
     * filtered to their own downline.
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
