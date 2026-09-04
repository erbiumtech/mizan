<?php

namespace App\Notifications;

use App\Support\Broadcasting;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * "Something you own has changed" — the bell's general case.
 *
 * The eight notifications that came before this each say something specific: a payslip was
 * issued, an environment went down, a claim needs approving. This one carries what is left
 * over — any audited record changing under the person it belongs to — which is most of what
 * happens in an application like this and had no signal at all.
 *
 * **Primitives, not a model.** Every notification here is queued, and a queued job holding a
 * tenant model is a job that has to resolve the tenant again on the far side to unserialize
 * it. The title, the line and the link are worked out where the change happened — inside the
 * request, with the company current — and what travels is three strings.
 *
 * **No mail channel, deliberately.** An email for every edit is a mailbox nobody reads, and
 * a notification nobody reads is worse than none: it teaches people to ignore the ones that
 * matter. This goes to the bell and over the socket, where the cost of being wrong is a
 * glance.
 */
class RecordChanged extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $title,
        public string $body,
        public ?string $url = null,
        public bool $isDeletion = false,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return Broadcasting::channels(['database', 'broadcast']);
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        $notification = FilamentNotification::make()
            ->title($this->title)
            ->body($this->body);

        // A deletion is the one of these that cannot be undone by opening the record, so it
        // is the one worth colouring. Everything else is an ordinary edit.
        $this->isDeletion ? $notification->warning() : $notification->info();

        // No link on a deletion: the record it would open is gone, and a link to a 404 reads
        // as the application having lost something rather than somebody having removed it.
        if ($this->url !== null && ! $this->isDeletion) {
            $notification->actions([
                Action::make('view')->label('Open')->url($this->url),
            ]);
        }

        return $notification->getDatabaseMessage();
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toDatabase($notifiable));
    }
}
