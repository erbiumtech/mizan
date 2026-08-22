<?php

namespace App\Modules\ConstructionQhse\Notifications;

use App\Modules\ConstructionQhse\Models\Competency;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Somebody's ticket is running out — §17.5.
 *
 * **The mail says who cannot work and what they cannot do**, not that a date is approaching. "Competency expiring in 7
 * days" is a calendar entry; "A. Labourer's confined-space ticket runs out on the 14th, and he is on the pump chamber"
 * is a reason to move somebody.
 *
 * Sent once per threshold crossed, never once per day, for the reason `DocumentExpiring` gives: "a job that mails the
 * same warning for thirty days trains somebody to filter it, and then the one that mattered is filtered too".
 *
 * **A mandatory ticket reads differently from a discretionary one**, because they are different events: one is a gap in
 * the file and the other stops somebody working. §17.5's `is_mandatory` is what makes the distinction, and the
 * notification is where it is heard.
 */
class CompetencyExpiring extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Competency $competency,
        public int $days,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database', 'broadcast'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $person = $this->competency->sitePersonnel;

        $mail = (new MailMessage)
            ->subject($this->subject())
            ->greeting("Hello {$notifiable->name},")
            ->line($this->body());

        $mail->line(
            $this->competency->kindLabel().': '.$this->competency->displayName()
            .($this->competency->issuing_body ? ', issued by '.$this->competency->issuing_body : '')
            .', expiring '.$this->competency->expires_on?->format('d M Y').'.'
        );

        if ($person !== null) {
            $mail->line(
                $person->displayName().' is on the register for '
                .($person->job?->code ?? 'this job')
                .($person->trade ? ' as '.$person->trade : '').'.'
            );
        }

        return $mail->line(
            $this->competency->is_mandatory
                ? 'This one is marked mandatory: once it lapses they should not be doing that work. Renew it, or move '
                    .'them off it — under Quality & Safety → Site personnel.'
                : 'Renew it under Quality & Safety → Site personnel.'
        );
    }

    public function toDatabase(object $notifiable): array
    {
        $notification = FilamentNotification::make()
            ->title($this->subject())
            ->body($this->body());

        // Lapsed and mandatory is somebody working who should not be; anything else is still recoverable paperwork.
        $this->days < 0 && $this->competency->is_mandatory
            ? $notification->danger()
            : $notification->warning();

        return $notification->getDatabaseMessage();
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toDatabase($notifiable));
    }

    private function subject(): string
    {
        $who = $this->competency->sitePersonnel?->name ?? 'Somebody';
        $what = $this->competency->title;

        return match (true) {
            $this->days < 0 => "{$who}: {$what} has EXPIRED",
            $this->days === 0 => "{$who}: {$what} expires TODAY",
            default => "{$who}: {$what} expires in {$this->days} day(s)",
        };
    }

    /** What it means, rather than what the date is. */
    private function body(): string
    {
        $who = $this->competency->sitePersonnel?->displayName() ?? 'A person on the register';

        if (! $this->competency->is_mandatory) {
            return "{$who} holds a {$this->competency->title} that is due for renewal.";
        }

        return $this->days < 0
            ? "{$who} is on site with a lapsed {$this->competency->title}, which is marked as mandatory for the work "
                .'they do. Until it is renewed they should not be doing it.'
            : "{$who} needs their {$this->competency->title} renewed. It is marked mandatory, so when it lapses they "
                .'stop being able to do that work.';
    }
}
