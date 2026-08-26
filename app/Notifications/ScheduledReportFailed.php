<?php

namespace App\Notifications;

use App\Modules\Core\Models\User;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A scheduled report has stopped arriving, and its owner is told — Phase 8, item 8.
 *
 * > Retries are bounded and the owner is notified after repeated failure, because a scheduled report that
 * > quietly stopped arriving is worse than one that was never set up: everybody assumes the silence means
 * > nothing happened.
 *
 * Sent once, after the attempts are exhausted, rather than per attempt: three emails about one failure is how
 * a warning becomes something people filter.
 */
class ScheduledReportFailed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $report,
        public string $timetable,
        public string $error,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return $notifiable instanceof User ? ['mail', 'database'] : ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('A scheduled report could not be sent: '.$this->report)
            ->greeting('Hello,')
            ->line($this->report.' ('.$this->timetable.') could not be sent, after three attempts.')
            ->line('The reason given was: '.$this->error)
            ->line('The report itself is still there to read. Nothing was sent to its recipients for this period.');
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title('A scheduled report could not be sent')
            ->body($this->report.' — '.$this->error)
            ->danger()
            ->getDatabaseMessage();
    }
}
