<?php

namespace App\Modules\ConstructionContracts\Notifications;

use App\Modules\ConstructionContracts\Models\ComplianceDocument;
use App\Support\Broadcasting;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A subcontractor's cover is about to lapse — sent once per threshold crossed, never once per day.
 *
 * The same shape as `DocumentExpiring`, and for the reason that one gives: "a job that mails the same warning for
 * thirty days trains somebody to filter it, and then the one that mattered is filtered too".
 *
 * The mail says what the lapse will *stop*, which is the difference between this and an employee document. A lapsed
 * public liability policy does not merely need chasing — it will refuse the next certificate (§12), and somebody who
 * knows that renews it before the payment run rather than after the refusal.
 */
class ComplianceDocumentExpiring extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public ComplianceDocument $document,
        public int $days,
    ) {}

    public function via(object $notifiable): array
    {
        return Broadcasting::channels(['mail', 'database', 'broadcast']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->subject())
            ->greeting("Hello {$notifiable->name},")
            ->line($this->body());

        if ($this->document->contract) {
            // Named, so the reader knows which payment is about to stop rather than which policy is about to lapse.
            $mail->line(
                'It is required on '.$this->document->contract->contract_number
                .', where the next certificate will be refused without it.'
            );
        }

        return $mail->line('Record the renewal in the compliance register.');
    }

    public function toDatabase(object $notifiable): array
    {
        $notification = FilamentNotification::make()
            ->title($this->subject())
            ->body($this->body());

        // Already gone is a refusal waiting to happen; still in date is a reminder.
        $this->days < 0 ? $notification->danger() : $notification->warning();

        return $notification->getDatabaseMessage();
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toDatabase($notifiable));
    }

    private function subject(): string
    {
        $what = $this->document->label().' for '.$this->party();

        return $this->days < 0
            ? "{$what} has EXPIRED"
            : "{$what} expires in {$this->days} day(s)";
    }

    private function body(): string
    {
        $on = $this->document->expires_on?->format('d M Y');

        return $this->days < 0
            ? "It lapsed on {$on}, ".abs($this->days).' day(s) ago, so certificates against it will be refused.'
            : "It lapses on {$on}.";
    }

    /** The subcontractor, where Invoicing is present to name them — §18's guarded coupling, so never assumed. */
    private function party(): string
    {
        return $this->document->contact?->name
            ?? $this->document->contract?->contact?->name
            ?? $this->document->contract?->contract_number
            ?? 'a subcontractor';
    }
}
