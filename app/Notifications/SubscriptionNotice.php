<?php

namespace App\Notifications;

use App\Support\Subscriptions;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Spatie\Multitenancy\Jobs\NotTenantAware;

/**
 * "Your workspace is suspended" — the one email billing:enforce sends, docs/saas-launch-plan.md §6.
 *
 * Routed to an address rather than a user (the billing contact lives in the
 * operator's tenant, not in `users`), and `NotTenantAware` because it is sent
 * from a landlord command with no tenant current.
 */
class SubscriptionNotice extends Notification implements NotTenantAware, ShouldQueue
{
    use Queueable;

    public function __construct(public string $companyName, public string $reason) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject("{$this->companyName}: access suspended");

        return match ($this->reason) {
            Subscriptions::REASON_TRIAL_EXPIRED => $mail
                ->line("The trial for {$this->companyName} has ended and nobody has subscribed, so sign-in is suspended.")
                ->line('Nothing has been deleted. Subscribe from the Subscription page once you are back in, or reply to this email and we will reopen it for you.'),
            default => $mail
                ->line("An invoice for {$this->companyName} is past due by more than ".config('saas.overdue_days', 30).' days, so sign-in is suspended.')
                ->line('Nothing has been deleted. Access returns on its own once the invoice is paid; reply to this email if a payment is already on its way.'),
        };
    }
}
