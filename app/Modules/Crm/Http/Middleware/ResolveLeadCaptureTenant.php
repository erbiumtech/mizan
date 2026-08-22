<?php

namespace App\Modules\Crm\Http\Middleware;

use App\Modules\Core\Models\Company;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The two gates, before anything is read or written.
 *
 * Copied deliberately from ResolveStatusPageTenant, which is the precedent §3 cites: the
 * status page is unauthenticated, token-gated, AND additionally gated by a per-company
 * setting. **Two gates, not one** — so a leaked token can be closed by switching the setting
 * off, without a deploy.
 *
 * 404 throughout rather than 403 or 401. An endpoint that distinguishes "wrong token" from
 * "capture is off" tells somebody probing which of the two to keep trying, and an unlisted
 * endpoint should not confirm it exists.
 */
class ResolveLeadCaptureTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $company = Company::where('slug', $request->route('company'))->first();

        abort_unless($company, 404);

        $company->makeCurrent();

        try {
            $enabled = (bool) setting('crm.lead_capture.enabled', false);
            $token = (string) setting('crm.lead_capture.token', '');

            abort_unless($enabled, 404);

            // hash_equals rather than ===: a timing-distinguishable comparison on a
            // long-lived shared secret is worth avoiding even here.
            abort_unless(
                $token !== '' && hash_equals($token, (string) $request->route('token')),
                404
            );

            $request->attributes->set('leadCaptureCompany', $company);

            return $next($request);
        } finally {
            // Never leave a tenant current on a public request — the same discipline the
            // status page keeps.
            Company::forgetCurrent();
        }
    }
}
