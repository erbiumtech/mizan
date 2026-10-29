<?php

namespace App\Modules\Projects\Notifications;

use App\Modules\Projects\Models\ProjectEnvironment;
use App\Support\Broadcasting;
use App\Support\Contracts\CategorisedNotification;
use App\Support\TemplatedMail;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Slack\SlackMessage;

class CertificateExpiring extends Notification implements CategorisedNotification, ShouldQueue
{
    use Queueable;

    public function __construct(
        public ProjectEnvironment $environment,
        public int $daysRemaining,
    ) {}

    public function notificationCategory(): string
    {
        return 'monitoring';
    }

    public function via(object $notifiable): array
    {
        return Broadcasting::channels((array) config('projects.alerts.channels', ['mail']));
    }

    public function toMail(object $notifiable): MailMessage
    {
        $project = $this->environment->project;
        $label = $this->environment->label();

        $message = (new MailMessage)
            ->subject("TLS certificate expires in {$this->daysRemaining} day(s): {$project->name} {$label}")
            ->greeting("Hello {$notifiable->name},")
            ->line("The certificate for {$project->name} ({$project->code}) — {$label} expires on "
                .$this->environment->ssl_expires_at->format('j M Y H:i').'.')
            ->line("That is {$this->daysRemaining} day(s) from now.");

        if ($this->environment->ssl_issuer) {
            $message->line('Issuer: '.$this->environment->ssl_issuer);
        }

        if ($this->daysRemaining <= 7) {
            $message->error();
        }

        return TemplatedMail::wrap($message, 'certificate_expiring', [
            'employee_name' => $notifiable->name ?? null,
            'project' => $project->name,
            'environment' => $label,
            'expires_on' => $this->environment->ssl_expires_at->format('j M Y H:i'),
            'days' => (string) $this->daysRemaining,
            'company' => \App\Modules\Core\Models\Company::current()?->name,
        ]);
    }

    public function toSlack(object $notifiable): SlackMessage
    {
        $project = $this->environment->project;

        return (new SlackMessage)->text(
            ":lock: Certificate for *{$project->name}* {$this->environment->label()}"
            ." expires in {$this->daysRemaining} day(s)"
        );
    }

    public function toDatabase(object $notifiable): array
    {
        $project = $this->environment->project;
        $label = $this->environment->label();

        $notification = FilamentNotification::make()
            ->title("Certificate expires in {$this->daysRemaining} day(s)")
            ->body("{$project->name} {$label} — expires ".$this->environment->ssl_expires_at->format('j M Y H:i').'.');

        $this->daysRemaining <= 7 ? $notification->danger() : $notification->warning();

        return $notification->getDatabaseMessage();
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toDatabase($notifiable));
    }
}
