<?php

namespace App\Modules\Core\Listeners;

use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Str;

/**
 * The sign-in log — docs/saas-launch-plan.md §7.7.
 *
 * No table: three rows on the landlord activity log, which the platform panel
 * already lists, with the address and the browser on each. A failed attempt is
 * logged against the email typed rather than a user, so an enumeration attempt
 * — the same wrong password against fifty addresses — is visible as one.
 *
 * **A sign-in belongs to a person, not to a company**, and the panels sign people in
 * outside any company — so the row cannot simply claim one. It claims the company of
 * somebody who belongs to exactly one, which is nearly everybody, and that is what
 * lets a tenant see its own sign-ins on its own activity log
 * (docs/saas-launch-plan.md §14.9). Somebody who belongs to several is left
 * company-less rather than attributed to a guess; the platform log still has them.
 *
 * A successful sign-in also stamps `users.last_login_at`. The log answers "what
 * happened"; the column answers "who has not been here since March", and answering
 * that out of the log means sorting every row in it.
 */
class LogAuthenticationEvent
{
    public function handle(Login|Logout|Failed $event): void
    {
        $request = request();
        $properties = [
            'ip' => $request?->ip(),
            'user_agent' => Str::limit((string) $request?->userAgent(), 255, ''),
            'guard' => $event->guard,
        ];

        if ($event instanceof Failed) {
            $email = Str::lower(trim((string) ($event->credentials['email'] ?? '')));

            activity('Auth')
                ->withProperties($properties + ['email' => $email])
                ->event('failed')
                ->log('Failed sign-in'.($email !== '' ? " for {$email}" : ''));

            return;
        }

        if ($event->user === null) {
            return;
        }

        if ($event instanceof Login) {
            // forceFill: these are not fillable and must never be settable from a form.
            $event->user->forceFill([
                'last_login_at' => now(),
                'last_login_ip' => Str::limit((string) $request?->ip(), 45, ''),
            ])->saveQuietly();
        }

        $log = activity('Auth')
            ->causedBy($event->user)
            ->performedOn($event->user)
            ->withProperties($properties)
            ->event($event instanceof Login ? 'login' : 'logout');

        if (($company = $this->soleCompanyOf($event->user)) !== null) {
            $log->tap(fn ($activity) => $activity->company_id = $company);
        }

        $log->log($event instanceof Login ? 'Signed in' : 'Signed out');
    }

    /**
     * The one company this person belongs to, where there is exactly one.
     *
     * Null for somebody in two, deliberately. Attributing a sign-in to whichever
     * company happened to be first would put one company's row on another company's
     * log, which is worse than having no row: a tenant reading its own sign-in list has
     * to be able to believe it.
     */
    private function soleCompanyOf(object $user): ?int
    {
        if (! method_exists($user, 'companies')) {
            return null;
        }

        $companies = $user->companies()->pluck('companies.id');

        return $companies->count() === 1 ? (int) $companies->first() : null;
    }
}
