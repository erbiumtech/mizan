<?php

namespace App\Modules\Work\Notifications;

use App\Modules\Core\Models\Company;
use App\Modules\Work\Filament\Resources\WorkItems\WorkItemResource;
use App\Modules\Work\Models\WorkItem;
use App\Support\Broadcasting;
use App\Support\Contracts\CategorisedNotification;
use App\Support\TemplatedMail;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "This is yours now" — docs/project-management-plan.md §10.1.
 *
 * The bell, the browser and the inbox, like every other message here. The mail carries the key,
 * the title and who did it, and never the description: a mail is forwarded, and the description
 * is the one field that leaks.
 */
class WorkItemAssigned extends Notification implements CategorisedNotification, ShouldQueue
{
    use Queueable;

    public function __construct(public WorkItem $item, public ?string $assignedBy = null) {}

    public function notificationCategory(): string
    {
        return 'work';
    }

    public function via(object $notifiable): array
    {
        return Broadcasting::channels(['database', 'broadcast', 'mail']);
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title($this->item->key.' is yours')
            ->body($this->item->title.($this->assignedBy !== null ? ' — assigned by '.$this->assignedBy : ''))
            ->icon('heroicon-o-user-circle')
            ->info()
            ->actions([
                Action::make('open')->label('Open')->url(WorkItemResource::getUrl('edit', ['record' => $this->item->getKey()])),
            ])
            ->getDatabaseMessage();
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toDatabase($notifiable));
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->action('Open '.$this->item->key, WorkItemResource::getUrl('edit', ['record' => $this->item->getKey()]));

        return TemplatedMail::apply(
            $mail,
            'work_item_assigned',
            [
                'employee_name' => $notifiable->name ?? null,
                'key' => $this->item->key,
                'title' => $this->item->title,
                'project' => $this->item->project?->name,
                'assigned_by' => $this->assignedBy,
                'company' => Company::current()?->name,
            ],
            subject: $this->item->key.' is yours: '.$this->item->title,
            greeting: 'Hello '.($notifiable->name ?? '').',',
            lines: [
                ($this->assignedBy ?? 'Somebody').' assigned '.$this->item->key.' — '.$this->item->title.' — to you'
                    .($this->item->project ? ' on '.$this->item->project->name : '').'.',
            ],
        );
    }
}
