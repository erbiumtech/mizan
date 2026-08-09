<?php

namespace App\Modules\Projects\Http\Middleware;

use App\Modules\Core\Models\Company;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The public status page sits outside the Filament panel, so the panel's
 * tenancy middleware never runs for it. Without making the company current
 * here, every query would hit the landlord connection.
 *
 * Also enforces the two gates before anything is read: the page must be enabled
 * for that company, and the token in the URL must match. Both failures 404
 * rather than 403 — an unlisted page shouldn't confirm it exists.
 */
class ResolveStatusPageTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $company = Company::where('slug', $request->route('company'))->first();

        abort_unless($company, 404);

        $company->makeCurrent();

        try {
            $enabled = (bool) setting('projects.status_page.enabled', false);
            $token = (string) setting('projects.status_page.token', '');

            abort_unless($enabled, 404);
            abort_unless(
                $token !== '' && hash_equals($token, (string) $request->route('token')),
                404
            );

            $request->attributes->set('statusPageCompany', $company);

            return $next($request);
        } finally {
            // Never leave a tenant current on a public request.
            Company::forgetCurrent();
        }
    }
}
