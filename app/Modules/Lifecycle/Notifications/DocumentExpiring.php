<?php

namespace App\Modules\Lifecycle\Notifications;

use App\Modules\Lifecycle\Models\EmployeeDocument;
use App\Support\Broadcasting;
use App\Support\Contracts\CategorisedNotification;
use App\Support\TemplatedMail;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent once per threshold crossed, never once per day.
 *
 * The distinction is the whole design: a job that mails the same warning for thirty
 * days trains somebody to filter it, and then the one that mattered is filtered too.
 */
class DocumentExpiring extends Notification implements CategorisedNotification, ShouldQueue
{
    use Queueable;

    public function __construct(
        public EmployeeDocument $document,
        public int $days,
    ) {}

    public function notificationCategory(): string
    {
        return 'documents';
    }

    public function via(object $notifiable): array
    {
        return Broadcasting::channels(['mail', 'database', 'broadcast']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->subject())
            ->greeting("Hello {$notifiable->name},")
            ->line($this->body())
            ->line('Open the employee\'s documents to record the renewal.');

        return TemplatedMail::wrap($mail, 'employee_document_expiring', [
            'employee_name' => $this->employeeName(),
            'recipient_name' => $notifiable->name ?? null,
            'document' => ucfirst($this->document->kind),
            'expires_on' => $this->document->expires_on?->format('d M Y'),
            'days' => (string) $this->days,
            'company' => \App\Modules\Core\Models\Company::current()?->name,
        ]);
    }

    public function toDatabase(object $notifiable): array
    {
        $notification = FilamentNotification::make()
            ->title($this->subject())
            ->body($this->body());

        $this->days < 0 ? $notification->danger() : $notification->warning();

        return $notification->getDatabaseMessage();
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toDatabase($notifiable));
    }

    private function employeeName(): string
    {
        return $this->document->employee?->user?->name
            ?? $this->document->employee?->name
            ?? 'An employee';
    }

    private function subject(): string
    {
        $kind = ucfirst($this->document->kind);

        // "Expired 40 days ago" and "expires in 40 days" are different problems, and
        // the subject line is where somebody decides whether to open the mail.
        return $this->days < 0
            ? "{$kind} for {$this->employeeName()} has EXPIRED"
            : "{$kind} for {$this->employeeName()} expires in {$this->days} day(s)";
    }

    private function body(): string
    {
        $on = $this->document->expires_on?->format('d M Y');

        return $this->days < 0
            ? "It lapsed on {$on}, ".abs($this->days).' day(s) ago.'
            : "It lapses on {$on}.";
    }
}
