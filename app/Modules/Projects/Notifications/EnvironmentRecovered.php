<?php

namespace App\Modules\Projects\Notifications;

use App\Modules\Projects\Models\ProjectEnvironment;
use App\Modules\Projects\Models\ProjectEnvironmentIncident;
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

class EnvironmentRecovered extends Notification implements CategorisedNotification, ShouldQueue
{
    use Queueable;

    public function __construct(
        public ProjectEnvironment $environment,
        public ProjectEnvironmentIncident $incident,
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
            ->success()
            ->subject("Recovered: {$project->name} {$label}")
            ->greeting("Hello {$notifiable->name},")
            ->line("{$project->name} ({$project->code}) — {$label} is answering again.")
            ->line('Downtime: '.$this->incident->durationForHumans()
                .' (from '.$this->incident->started_at->format('j M H:i')
                .' to '.$this->incident->resolved_at->format('j M H:i').').')
            ->line('Failed checks during the incident: '.$this->incident->failure_count.'.');

        return TemplatedMail::wrap($message, 'environment_recovered', [
            'employee_name' => $notifiable->name ?? null,
            'project' => $project->name,
            'environment' => $label,
            'downtime' => $this->incident->durationForHumans(),
            'company' => \App\Modules\Core\Models\Company::current()?->name,
        ]);
    }

    public function toSlack(object $notifiable): SlackMessage
    {
        $project = $this->environment->project;

        return (new SlackMessage)->text(
            ":white_check_mark: Recovered — *{$project->name}* {$this->environment->label()}"
            .' after '.$this->incident->durationForHumans()
        );
    }

    public function toDatabase(object $notifiable): array
    {
        $project = $this->environment->project;
        $label = $this->environment->label();

        return FilamentNotification::make()
            ->title("Recovered: {$project->name} {$label}")
            ->body('Downtime: '.$this->incident->durationForHumans().'.')
            ->success()
            ->getDatabaseMessage();
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toDatabase($notifiable));
    }
}
