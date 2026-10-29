<?php

namespace App\Modules\Crm\Http\Middleware;

use App\Modules\Core\Models\Company;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The two gates on the confirmation page — docs/saas-launch-plan.md §8.5: the
 * signature on the link and the per-company switch. 404 for both, like every
 * public page here. The same shape as ResolveLeadCaptureTenant.
 */
class ResolveFieldVisitTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $company = Company::where('slug', $request->route('company'))->first();

        abort_unless($company, 404);
        abort_unless($request->hasValidSignature(), 404);

        $company->makeCurrent();

        try {
            abort_unless((bool) setting('crm.field_visits.enabled', config('crm.field_visits.enabled', false)), 404);

            $request->attributes->set('fieldVisitCompany', $company);

            return $next($request);
        } finally {
            Company::forgetCurrent();
        }
    }
}
