<?php

namespace App\Modules\Recruitment\Http\Middleware;

use App\Modules\Core\Models\Company;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The two gates, before anything is read or written — docs/saas-launch-plan.md §7.4.
 *
 * The lead-capture middleware's shape exactly: the token AND a per-company
 * setting, 404 for both failures, the tenant forgotten in `finally` so nothing
 * leaks out of a public request.
 */
class ResolveCareersTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $company = Company::where('slug', $request->route('company'))->first();

        abort_unless($company, 404);

        $company->makeCurrent();

        try {
            $enabled = (bool) setting('recruitment.careers.enabled', false);
            $token = (string) setting('recruitment.careers.token', '');

            abort_unless($enabled, 404);
            abort_unless($token !== '' && hash_equals($token, (string) $request->route('token')), 404);

            $request->attributes->set('careersCompany', $company);

            return $next($request);
        } finally {
            Company::forgetCurrent();
        }
    }
}
