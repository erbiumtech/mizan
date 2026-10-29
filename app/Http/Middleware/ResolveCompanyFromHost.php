<?php

namespace App\Http\Middleware;

use App\Modules\Core\Models\Company;
use App\Support\CustomDomains;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A custom host shows one company — docs/saas-launch-plan.md §8.8.
 *
 * On a company's own domain, `/` and `/admin` go to its panel; another
 * company's slug under `/admin` is a 404, the same "the URL names a company"
 * boundary ResolveCompanyFromRoute keeps; and the request remembers whose host
 * it is, so the login page — which has no tenant yet — can wear the right logo.
 * On the platform host nothing changes.
 */
class ResolveCompanyFromHost
{
    public function handle(Request $request, Closure $next): Response
    {
        $company = CustomDomains::companyForHost($request->getHost());

        if ($company === null) {
            return $next($request);
        }

        $request->attributes->set('hostCompany', $company);

        $path = trim($request->path(), '/');

        if ($path === '' || $path === 'admin') {
            return redirect('/admin/'.$company->slug);
        }

        if (str_starts_with($path, 'admin/')) {
            $segment = explode('/', $path)[1] ?? '';

            if ($segment !== '' && $segment !== $company->slug && Company::query()->where('slug', $segment)->exists()) {
                abort(404);
            }
        }

        return $next($request);
    }
}
