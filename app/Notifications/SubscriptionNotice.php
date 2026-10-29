<?php

namespace App\Notifications;

use App\Support\Contracts\CategorisedNotification;
use App\Support\Subscriptions;
use App\Support\TemplatedMail;
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
class SubscriptionNotice extends Notification implements CategorisedNotification, NotTenantAware, ShouldQueue
{
    use Queueable;

    public function __construct(public string $companyName, public string $reason) {}

    /** @return array<int, string> */
    public function notificationCategory(): string
    {
        return 'account';
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject("{$this->companyName}: access suspended");

        // No TemplatedMail here, deliberately — docs/saas-launch-plan.md §14.7 asks for
        // coverage of every mail, and this is the stated exception. It is sent *because*
        // a company's access has stopped, from the platform rather than from inside the
        // company: there is no tenant current to read a template out of, and a company
        // rewording its own suspension notice is not a thing to offer.
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
