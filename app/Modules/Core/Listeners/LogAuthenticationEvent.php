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
 * `company_id` stays null: the panels sign people in outside any company, and a
 * row that claimed one would be wrong about who was being served.
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

        activity('Auth')
            ->causedBy($event->user)
            ->performedOn($event->user)
            ->withProperties($properties)
            ->event($event instanceof Login ? 'login' : 'logout')
            ->log($event instanceof Login ? 'Signed in' : 'Signed out');
    }
}
