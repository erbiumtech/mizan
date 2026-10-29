<?php

namespace App\Modules\Crm\Notifications;

use App\Support\Contracts\CategorisedNotification;
use App\Support\TemplatedMail;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The witness's link — docs/saas-launch-plan.md §8.5. On-demand, to the contact's address. */
class VisitConfirmationRequest extends Notification implements CategorisedNotification
{
    use Queueable;

    public function __construct(
        public readonly string $companyName,
        public readonly string $visitorName,
        public readonly string $visitedOn,
        public readonly string $url,
    ) {}

    public function notificationCategory(): string
    {
        return 'company';
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject("{$this->companyName}: please confirm {$this->visitorName}'s visit")
            ->line("{$this->visitorName} from {$this->companyName} recorded a visit to you on {$this->visitedOn}.")
            ->line('If that is right, one tap below confirms it. It is not a signature on anything; it only tells their manager the visit happened.')
            ->action('Confirm the visit', $this->url)
            ->line('The link works for one day.');

        // One of the few that reaches somebody outside the company, so the wording
        // being the company's own matters more here than on an internal alert.
        return TemplatedMail::wrap($mail, 'visit_confirmation_request', [
            'visitor' => $this->visitorName,
            'visited_on' => $this->visitedOn,
            'company' => $this->companyName,
        ]);
    }

    /** The same words, for WhatsApp. */
    public function toText(): string
    {
        return "{$this->visitorName} from {$this->companyName} recorded a visit to you on {$this->visitedOn}. "
            ."If that is right, please confirm here (one tap, valid for a day): {$this->url}";
    }
}
