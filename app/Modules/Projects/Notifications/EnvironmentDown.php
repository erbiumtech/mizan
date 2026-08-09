<?php

namespace App\Modules\Projects\Notifications;

use App\Modules\Projects\Filament\Resources\Projects\ProjectResource;
use App\Modules\Projects\Models\ProjectEnvironment;
use App\Modules\Projects\Models\ProjectEnvironmentIncident;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EnvironmentDown extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public ProjectEnvironment $environment,
        public ProjectEnvironmentIncident $incident,
        public bool $isReminder = false,
    ) {}

    public function via(object $notifiable): array
    {
        return (array) config('projects.alerts.channels', ['mail']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $project = $this->environment->project;
        $label = $this->environment->label();
        $subject = $this->isReminder
            ? "Still down: {$project->name} {$label}"
            : "{$project->name} {$label} is down";

        $message = (new MailMessage)
            ->error()
            ->subject($subject)
            ->greeting("Hello {$notifiable->name},")
            ->line("{$project->name} ({$project->code}) — {$label} has been unreachable since "
                .$this->incident->started_at->format('j M Y H:i').' ('.$this->incident->durationForHumans().').')
            ->line('Failed checks: '.$this->incident->failure_count.'.');

        if ($this->incident->last_status_code) {
            $message->line('Last HTTP status: '.$this->incident->last_status_code.'.');
        }

        if ($this->incident->last_error) {
            $message->line('Last error: '.$this->incident->last_error);
        }

        if ($this->isReminder) {
            $message->line('This is reminder '.$this->incident->reminders_sent.' of '
                .config('projects.alerts.max_reminders').'; no further reminders will be sent for this incident.');
        }

        return $message->action('Open project', $this->projectUrl());
    }

    public function toDatabase(object $notifiable): array
    {
        $project = $this->environment->project;
        $label = $this->environment->label();

        return FilamentNotification::make()
            ->title($this->isReminder ? "Still down: {$project->name} {$label}" : "{$project->name} {$label} is down")
            ->body('Unreachable since '.$this->incident->started_at->format('j M Y H:i').' ('.$this->incident->durationForHumans().').')
            ->danger()
            ->actions([
                Action::make('open')
                    ->label('Open project')
                    ->url($this->projectUrl()),
            ])
            ->getDatabaseMessage();
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toDatabase($notifiable));
    }

    /**
     * Only sent when laravel/slack-notification-channel is installed and
     * 'slack' is in projects.alerts.channels.
     */
    public function toSlack(object $notifiable): string
    {
        $project = $this->environment->project;

        return ($this->isReminder ? ':hourglass: Still down' : ':rotating_light: Down')
            ." — *{$project->name}* {$this->environment->label()}"
            .' (since '.$this->incident->started_at->format('H:i').', '
            .$this->incident->failure_count.' failed checks)';
    }

    protected function projectUrl(): string
    {
        try {
            return ProjectResource::getUrl('view', [
                'record' => $this->environment->project_id,
            ]);
        } catch (\Throwable) {
            // Queued jobs run without a panel/tenant URL context.
            return url('/');
        }
    }
}
