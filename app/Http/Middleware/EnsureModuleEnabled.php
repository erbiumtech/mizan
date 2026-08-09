<?php

namespace App\Http\Middleware;

use App\Support\Modules;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Closes the direct-URL gap. canAccess() hides a module from the sidebar, global
 * search and the ⌘K palette, but does nothing for someone who types the URL or
 * calls the API — and because modules are licensed, that gap is the difference
 * between hiding a feature and not shipping it.
 *
 * Usage:
 *
 *     ->middleware('module:accounting')      // 403
 *     ->middleware('module:projects,404')    // 404, for pages that should not
 *                                            // confirm they exist
 *
 * Several modules may be listed; all must be available.
 */
class EnsureModuleEnabled
{
    public function handle(Request $request, Closure $next, string ...$parameters): Response
    {
        $status = 403;
        $modules = [];

        foreach ($parameters as $parameter) {
            if (ctype_digit($parameter)) {
                $status = (int) $parameter;

                continue;
            }

            $modules[] = $parameter;
        }

        // Whose licence applies is decided in one place — Modules::availableTo() —
        // shared with the canAccess() gate and the Gate::before deny, so the three
        // enforcement layers cannot disagree about which company they are judging.
        foreach ($modules as $module) {
            if (! modules()->availableTo($request->user(), $module)) {
                abort($status, $status === 403
                    ? 'The '.Modules::label($module).' module is not enabled for this company.'
                    : '');
            }
        }

        return $next($request);
    }
}
