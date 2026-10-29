<?php

namespace App\Http\Middleware;

use App\Support\Locales;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * The request's language — docs/saas-launch-plan.md §8.10.
 *
 * The panel takes the signed-in person's preference over the company's default;
 * the API takes `Accept-Language` first, because the phone knows what its owner
 * reads and carries no session. Carbon follows, so month and day names are in
 * the same language as the sentence around them.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next, string $source = 'panel'): Response
    {
        $locale = $source === 'api'
            ? (Locales::fromAcceptLanguage($request->header('Accept-Language')) ?? Locales::forUser($request->user()))
            : Locales::forUser($request->user());

        app()->setLocale($locale);
        Carbon::setLocale($locale);

        return $next($request);
    }
}
