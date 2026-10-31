<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Route;

/**
 * Register an API route group under both the bare `/api` prefix and the versioned `/api/v1` — the D4
 * decision in docs/desktop-mobile-plan.md: version the API before touching its shapes, keeping every
 * existing unprefixed route answering (deprecated) until the shipped clients are confirmed migrated.
 *
 * A module calls this instead of `Route::prefix('api')->middleware(...)->group(...)`; the routes inside
 * are defined once and mounted on both surfaces. A handler that must behave differently on the versioned
 * surface asks `$request->is('api/v1/*')` — which is where MprController corrects its 400 to a 404.
 */
class ApiRoutes
{
    /** @var array<int, string> */
    public const PREFIXES = ['api', 'api/v1'];

    /**
     * A group that exists on the versioned surface only — docs/desktop-mobile-plan.md A4: "new endpoints
     * land under /v1 only". Same stack as group(), mounted once, with the same `v1.` name prefix so a
     * route named here can be found the way a route from group() is.
     *
     * @param  array<int, string>  $middleware
     */
    public static function versioned(array $middleware, Closure $routes): void
    {
        Route::prefix('api/v1')
            ->name('v1.')
            ->middleware([\App\Http\Middleware\SetLocale::class.':api', ...$middleware])
            ->group($routes);
    }

    /**
     * @param  array<int, string>  $middleware
     */
    public static function group(array $middleware, Closure $routes): void
    {
        foreach (self::PREFIXES as $prefix) {
            // The versioned surface namespaces its route names (v1.accounts.index), because a named route
            // registered under both prefixes would collide and break route:cache.
            Route::prefix($prefix)
                ->name($prefix === 'api' ? '' : 'v1.')
                // Accept-Language first on the API — docs/saas-launch-plan.md §8.10. Prepended, so a
                // route group's own middleware cannot forget it.
                ->middleware([\App\Http\Middleware\SetLocale::class.':api', ...$middleware])
                ->group($routes);
        }
    }
}
