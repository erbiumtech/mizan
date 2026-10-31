<?php

namespace App\Modules\Work\Http\Middleware;

use App\Modules\Core\Models\Company;
use App\Modules\Work\Models\IntegrationConnection;
use App\Support\Integrations;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The tenant behind an inbound hook — docs/project-management-plan.md §8.3.
 *
 * The plan resolves the tenant "from the connection id the way the status page resolves its
 * tenant from the slug", and a connection id is a row in a tenant's own database — so the slug
 * is in the URL too, and that is what names the tenant. Copied from `ResolveLeadCaptureTenant`:
 * the company is made current for the request and forgotten after it, so nothing leaks into a
 * panel session, and every refusal is a 404 so that a probe learns nothing about which part was
 * wrong. The signature itself is checked by the controller, where a mismatch is the one honest
 * 401.
 */
class ResolveHookTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $company = Company::where('slug', $request->route('company'))->first();

        abort_unless($company, 404);
        abort_unless(Integrations::has((string) $request->route('provider')), 404);

        $company->makeCurrent();

        try {
            abort_unless(modules()->enabled('work'), 404);

            $connection = IntegrationConnection::query()
                ->whereKey($request->route('connection'))
                ->where('provider', $request->route('provider'))
                ->first();

            abort_unless($connection, 404);

            $request->attributes->set('hookCompany', $company);
            $request->attributes->set('hookConnection', $connection);

            return $next($request);
        } finally {
            Company::forgetCurrent();
        }
    }
}
