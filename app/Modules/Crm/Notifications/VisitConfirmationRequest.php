<?php

namespace App\Modules\Crm\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The witness's link — docs/saas-launch-plan.md §8.5. On-demand, to the contact's address. */
class VisitConfirmationRequest extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $companyName,
        public readonly string $visitorName,
        public readonly string $visitedOn,
        public readonly string $url,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("{$this->companyName}: please confirm {$this->visitorName}'s visit")
            ->line("{$this->visitorName} from {$this->companyName} recorded a visit to you on {$this->visitedOn}.")
            ->line('If that is right, one tap below confirms it. It is not a signature on anything; it only tells their manager the visit happened.')
            ->action('Confirm the visit', $this->url)
            ->line('The link works for one day.');
    }

    /** The same words, for WhatsApp. */
    public function toText(): string
    {
        return "{$this->visitorName} from {$this->companyName} recorded a visit to you on {$this->visitedOn}. "
            ."If that is right, please confirm here (one tap, valid for a day): {$this->url}";
    }
}
