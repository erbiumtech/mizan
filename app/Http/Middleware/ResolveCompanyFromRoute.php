<?php

namespace App\Http\Middleware;

use App\Modules\Core\Models\Company;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes the `{company}` in the URL the current tenant, for pages that live
 * outside the Filament panel.
 *
 * The panel resolves the tenant from /admin/{company} and everything inside it
 * reads the right database because of that. A standalone page — a printable
 * statement, a PDF — has no such thing: without this its queries would run on
 * the landlord connection and quietly show nothing, or worse, whatever the
 * landlord happens to hold under the same table name.
 *
 * Also the authorization boundary for those pages: the URL names a company, so
 * the first question is whether this user may be in it at all. Ordered ahead of
 * `module:` in the route, because whether a module is licensed is a question
 * about a company and there has to be one current to answer it.
 *
 * Resolved by slug here rather than through route-model binding: binding runs at
 * a point in the middleware stack this must come before, and a tenant resolved
 * after the queries have started is no tenant at all.
 */
class ResolveCompanyFromRoute
{
    public function handle(Request $request, Closure $next, string $parameter = 'company'): Response
    {
        $company = Company::where('slug', $request->route($parameter))->first();

        abort_unless($company, 404);
        abort_unless($request->user()?->canAccessTenant($company), 403);

        $company->activate();

        $request->attributes->set('company', $company);

        try {
            return $next($request);
        } finally {
            // A queue worker or a later request in the same process has no reason
            // to inherit this one's company.
            Company::forgetCurrent();
        }
    }
}
