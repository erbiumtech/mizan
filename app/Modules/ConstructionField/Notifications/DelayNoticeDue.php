<?php

namespace App\Modules\ConstructionField\Notifications;

use App\Modules\ConstructionField\Models\DelayEvent;
use App\Support\Broadcasting;
use App\Support\Num;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A delay event's notice period is running out — §13's clock, made audible.
 *
 * **This notification is the deliverable of Phase 9a**, not the register behind it. §13: *"A due-date with a
 * notification attached is worth more commercially than the entire programme: a valid claim lost to a missed notice is
 * the single most common way a contractor donates money, and it fails in absolute silence."*
 *
 * Sent once per threshold crossed, never once per day, for the reason `DocumentExpiring` gives: "a job that mails the
 * same warning for thirty days trains somebody to filter it, and then the one that mattered is filtered too".
 *
 * **The mail says what will be lost, not that a date is approaching.** "DE-4 notice due in 3 days" is a calendar entry;
 * "the claim for 12 days and 340,000 is lost on the 14th unless notice is served" is a reason to stop what you are
 * doing. Where nothing has been quantified yet — which is usual this early — it says that the entitlement itself goes,
 * which is worse rather than better.
 */
class DelayNoticeDue extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public DelayEvent $event,
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

        $mail->line(
            $this->event->displayName().' on '.($this->event->job?->code ?? 'the job')
            .' — '.$this->event->causeLabel().', which occurred on '
            .$this->event->occurred_on?->format('d M Y').'.'
        );

        return $mail->line(
            $this->days < 0
                ? 'Serve the notice anyway and record the date: whether a late notice is still good depends on the '
                    .'contract, and no notice at all never is.'
                : 'Serve the notice and record the date it went, under Site → Delay events.'
        );
    }

    public function toDatabase(object $notifiable): array
    {
        $notification = FilamentNotification::make()
            ->title($this->subject())
            ->body($this->body());

        // Past the date is money already gone; before it is still recoverable.
        $this->days < 0 ? $notification->danger() : $notification->warning();

        return $notification->getDatabaseMessage();
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toDatabase($notifiable));
    }

    private function subject(): string
    {
        $reference = $this->event->reference;

        return match (true) {
            $this->days < 0 => "{$reference}: the notice period has EXPIRED",
            $this->days === 0 => "{$reference}: notice is due TODAY",
            default => "{$reference}: notice due in {$this->days} day(s)",
        };
    }

    /** What is lost, rather than what date it is. */
    private function body(): string
    {
        $by = $this->event->notice_required_by?->format('d M Y');
        $stake = $this->stake();

        if ($this->days < 0) {
            return "The notice period ran out on {$by}, ".abs($this->days).' day(s) ago, and nothing has been served. '
                .$stake;
        }

        return "Notice must be served by {$by}. ".$stake;
    }

    /**
     * The money at risk, where anybody has said what it is.
     *
     * Unquantified is the usual state this early, and it is deliberately reported as worse rather than vaguer: an event
     * with no figures is one nobody has assessed, so what is at stake is the whole entitlement.
     */
    private function stake(): string
    {
        $days = $this->event->claimed_days;
        $cost = $this->event->cost_claimed;

        if ($days === null && $cost === null) {
            return 'Without it the entitlement goes entirely — time and money both, before anybody has worked out '
                .'what either was worth.';
        }

        $parts = [];

        if ($days !== null) {
            $parts[] = Num::trim($days).' day(s) of extension';
        }

        if ($cost !== null) {
            $parts[] = number_format((float) $cost, 2).' of cost';
        }

        return 'At stake: '.implode(' and ', $parts).'.';
    }
}
