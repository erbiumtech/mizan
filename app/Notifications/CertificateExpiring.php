<?php

namespace App\Notifications;

use App\Models\ProjectEnvironment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CertificateExpiring extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public ProjectEnvironment $environment,
        public int $daysRemaining,
    ) {}

    public function via(object $notifiable): array
    {
        return (array) config('projects.alerts.channels', ['mail']);
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

        return $message;
    }

    public function toSlack(object $notifiable): string
    {
        $project = $this->environment->project;

        return ":lock: Certificate for *{$project->name}* {$this->environment->label()}"
            ." expires in {$this->daysRemaining} day(s)";
    }
}
