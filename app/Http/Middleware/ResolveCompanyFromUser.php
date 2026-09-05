<?php

namespace App\Http\Middleware;

use App\Modules\Core\Models\Company;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes the API caller's company the current tenant.
 *
 * The panel resolves its tenant from /admin/{company}, and the pages outside it from a `{company}` route
 * segment (ResolveCompanyFromRoute). The mobile API had neither — `tenant_finder` is null and nothing names
 * a company in the URL — so every API request ran with no company current. Two things follow from that,
 * and both were found on disk rather than in a log: the tenant models query whatever the tenant connection
 * points at when nobody has pointed it, and the `public` disk is the *shared* root with its URL still
 * `/storage`. That is how `storage/app/public/Mpr/<Name>_<timestamp>.pdf` came to exist outside any
 * `tenants/{id}` directory, handed to mobile clients under a URL that only resolves on a host with the very
 * symlink PublicStorageIsNotExposedTest exists to forbid — and resolves there for anybody.
 *
 * A token names a user, not a company, so the company is read from membership. One membership is
 * unambiguous. Several are not, and guessing the first would answer one company's request with another's
 * data — so a client whose user belongs to several sends `X-Company: <slug>` and a request without it is
 * refused with a message saying what to send. A super admin has no memberships and always names one.
 *
 * Ordered after `auth:sanctum` (it needs the user) and before `module:` (a licence belongs to a company,
 * so one has to be current before that question can be answered — the same rule as ResolveCompanyFromRoute).
 */
class ResolveCompanyFromUser
{
    public const HEADER = 'X-Company';

    public function handle(Request $request, Closure $next): Response
    {
        $company = $this->resolve($request);

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

    private function resolve(Request $request): Company
    {
        $user = $request->user();

        abort_unless($user, 401);

        if ($slug = $request->header(self::HEADER)) {
            $company = Company::where('slug', $slug)->first();

            abort_unless($company && $user->canAccessTenant($company), 403, 'You do not have access to this company.');

            return $company;
        }

        $companies = $user->companies()->get();

        if ($companies->count() === 1) {
            return $companies->first();
        }

        abort(422, $companies->isEmpty()
            ? 'This account is not a member of any company. Send the '.self::HEADER.' header naming one if you administer the platform.'
            : 'This account belongs to several companies. Send the '.self::HEADER.' header naming which one this request is for.');
    }
}
