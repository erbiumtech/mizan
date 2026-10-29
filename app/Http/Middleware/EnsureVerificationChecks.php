<?php

namespace App\Http\Middleware;

use App\Support\VerificationChecks;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The gate — docs/saas-launch-plan.md §14.8.
 *
 * **It stops somebody going anywhere else, not signing in.** A refusal at the door
 * would be the wrong shape twice over: the thing they have to do to get past is
 * inside, and a person who cannot sign in cannot be told why. So the sign-in
 * succeeds and every screen but the one that clears the block redirects to it.
 *
 * The rule §14.8 opens with is enforced in {@see VerificationChecks}, not here: a
 * check the company cannot perform is not required, so `outstandingFor()` returns
 * nothing for it and nobody is held up by a WhatsApp provider that was switched off
 * last week.
 *
 * Fails open on any error. This runs on every page of the panel, and a verification
 * table that has not been migrated yet, or a check that throws, must not be the
 * reason a company cannot open its own payroll.
 */
class EnsureVerificationChecks
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $user = $request->user();

            if ($user === null || ! Filament::getTenant()) {
                return $next($request);
            }

            /*
             * The outstanding check first, and the order is a measured decision rather
             * than a preference. Asking the page whether it is accessible costs two
             * queries — the module licence and the employee record — and this runs on
             * every page of the panel. `outstandingFor()` costs nothing at all for a
             * company that requires nothing, which is every company by default, so the
             * cheap question is the one asked of everybody.
             */
            if (VerificationChecks::outstandingFor($user) === []) {
                return $next($request);
            }

            // The page itself, and everything Livewire sends while somebody is on it.
            if ($request->routeIs('*confirm-details*') || $request->hasHeader('X-Livewire')) {
                return $next($request);
            }

            $page = $this->confirmationPage();

            return $page === null ? $next($request) : redirect()->to($page::getUrl());
        } catch (Throwable) {
            return $next($request);
        }
    }

    /**
     * The page that clears the block, when this installation has one.
     *
     * Named rather than imported: the page belongs to the Employees module and this is
     * shared middleware, so it is resolved by string and simply does nothing where the
     * module is absent.
     *
     * @return class-string|null
     */
    private function confirmationPage(): ?string
    {
        $page = 'App\\Modules\\Employees\\Filament\\Pages\\ConfirmDetails';

        return class_exists($page) && $page::canAccess() ? $page : null;
    }
}
