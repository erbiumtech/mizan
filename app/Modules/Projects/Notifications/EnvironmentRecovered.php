<?php

namespace App\Modules\Projects\Notifications;

use App\Modules\Projects\Models\ProjectEnvironment;
use App\Modules\Projects\Models\ProjectEnvironmentIncident;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EnvironmentRecovered extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public ProjectEnvironment $environment,
        public ProjectEnvironmentIncident $incident,
    ) {}

    public function via(object $notifiable): array
    {
        return (array) config('projects.alerts.channels', ['mail']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $project = $this->environment->project;
        $label = $this->environment->label();

        return (new MailMessage)
            ->success()
            ->subject("Recovered: {$project->name} {$label}")
            ->greeting("Hello {$notifiable->name},")
            ->line("{$project->name} ({$project->code}) — {$label} is answering again.")
            ->line('Downtime: '.$this->incident->durationForHumans()
                .' (from '.$this->incident->started_at->format('j M H:i')
                .' to '.$this->incident->resolved_at->format('j M H:i').').')
            ->line('Failed checks during the incident: '.$this->incident->failure_count.'.');
    }

    public function toSlack(object $notifiable): string
    {
        $project = $this->environment->project;

        return ":white_check_mark: Recovered — *{$project->name}* {$this->environment->label()}"
            .' after '.$this->incident->durationForHumans();
    }
}
