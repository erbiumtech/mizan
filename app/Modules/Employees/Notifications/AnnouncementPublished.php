<?php

namespace App\Modules\Employees\Notifications;

use App\Modules\Employees\Models\Announcement;
use App\Support\Broadcasting;
use App\Support\Contracts\CategorisedNotification;
use App\Support\TemplatedMail;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * "The company has something to tell you" — docs/saas-launch-plan.md §7.6.
 *
 * The bell, the browser and the inbox, like every other message here: the
 * database and broadcast halves are the RecordChanged shape so the bell renders
 * them the same way, and the mail carries per-company wording under
 * `announcement_published`.
 */
class AnnouncementPublished extends Notification implements CategorisedNotification, ShouldQueue
{
    use Queueable;

    public function __construct(public Announcement $announcement) {}

    /** @return array<int, string> */
    public function notificationCategory(): string
    {
        return 'company';
    }

    public function via(object $notifiable): array
    {
        return Broadcasting::channels(['database', 'broadcast', 'mail']);
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title($this->announcement->title)
            ->body(Str::limit($this->announcement->body, 160))
            ->icon('heroicon-o-megaphone')
            ->info()
            ->getDatabaseMessage();
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toDatabase($notifiable));
    }

    public function toMail(object $notifiable): MailMessage
    {
        return TemplatedMail::apply(
            new MailMessage,
            'announcement_published',
            [
                'name' => $notifiable->name,
                'title' => $this->announcement->title,
                'body' => $this->announcement->body,
                'audience' => $this->announcement->audienceLabel(),
            ],
            subject: $this->announcement->title,
            greeting: "Hello {$notifiable->name},",
            lines: array_values(array_filter(preg_split('/\R{2,}/', trim($this->announcement->body)) ?: [])),
        );
    }
}
