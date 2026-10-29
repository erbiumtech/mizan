<?php

namespace App\Notifications;

use App\Support\Contracts\CategorisedNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Spatie\Multitenancy\Jobs\NotTenantAware;

/**
 * "Your export is ready" — docs/saas-launch-plan.md §9. Mail with the link; the
 * bell gets a RecordChanged beside it. NotTenantAware: sent from a landlord job.
 */
class TenantExportReady extends Notification implements CategorisedNotification, NotTenantAware, ShouldQueue
{
    use Queueable;

    public function __construct(public string $companyName, public string $url, public int $bytes) {}

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
        $size = $this->bytes >= 1048576
            ? round($this->bytes / 1048576, 1).' MB'
            : round($this->bytes / 1024).' KB';

        // No TemplatedMail: this one is NotTenantAware, so it is built with no company
        // current and there is no per-company wording to read. The shipped text is
        // what goes.
        return (new MailMessage)
            ->subject("{$this->companyName}: your data export is ready")
            ->greeting("Hello {$notifiable->name},")
            ->line("The export of {$this->companyName} is ready ({$size}): the whole database as SQL and every file the company uploaded, in one archive.")
            ->action('Download the export', $this->url)
            ->line('You need to be signed in as an Administrator of the company to download it. The link does not expire; the archive stays in the company\'s own storage.');
    }
}
