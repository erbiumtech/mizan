<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;
use Spatie\Multitenancy\Jobs\NotTenantAware;

/**
 * "Prove this mailbox is yours" — the only mail the signup door sends.
 *
 * Not Laravel's VerifyEmail: that one builds its link from the `verification.verify`
 * route, which this application does not have and must not have on the panels —
 * every existing user was created by a person and would be locked out on deploy.
 * The link here goes to the signup door alone (`signup.verify`), signed and
 * temporary, carrying the same `{id}/{hash}` pair Laravel uses so the controller
 * can check it the same way.
 *
 * No company wording through TemplatedMail: there is no company yet.
 *
 * `NotTenantAware`, because it is sent from a public page with no tenant current
 * and the queue guard would otherwise refuse it at dispatch — the password-reset
 * notification is on the exemption list for exactly this reason.
 */
class SignupVerification extends Notification implements NotTenantAware, ShouldQueue
{
    use Queueable;

    public function __construct(public string $companyName) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = URL::temporarySignedRoute(
            'signup.verify',
            now()->addHours((int) config('plans.signup.link_hours', 24)),
            ['user' => $notifiable->getKey(), 'hash' => sha1($notifiable->getEmailForVerification())],
        );

        return (new MailMessage)
            ->subject("Confirm your email to set up {$this->companyName}")
            ->greeting("Hello {$notifiable->name},")
            ->line("Confirm this is your email address and we will set up {$this->companyName} for you. "
                .'Nothing is created until you do.')
            ->action('Confirm email', $url)
            ->line('The link works for a day. If you did not sign up, ignore this email and nothing will happen.');
    }
}
