<?php

namespace App\Modules\Core\Http\Middleware;

use App\Modules\Core\Models\Company;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The verification page's two gates — docs/saas-launch-plan.md §7.5. The
 * lead-capture middleware's shape: token AND setting, 404 for both failures,
 * the tenant forgotten in `finally`.
 */
class ResolveVerificationTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $company = Company::where('slug', $request->route('company'))->first();

        abort_unless($company, 404);

        $company->makeCurrent();

        try {
            $enabled = (bool) setting('documents.verification.enabled', false);
            $token = (string) setting('documents.verification.token', '');

            abort_unless($enabled, 404);
            abort_unless($token !== '' && hash_equals($token, (string) $request->route('token')), 404);

            $request->attributes->set('verificationCompany', $company);

            return $next($request);
        } finally {
            Company::forgetCurrent();
        }
    }
}
