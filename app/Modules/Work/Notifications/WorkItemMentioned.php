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
 * "Somebody named you" — docs/project-management-plan.md §10.3.
 *
 * `$where` is "a comment" or "the description": enough to know where to look, without quoting the
 * text itself into a mail that will be forwarded.
 */
class WorkItemMentioned extends Notification implements CategorisedNotification, ShouldQueue
{
    use Queueable;

    public function __construct(public WorkItem $item, public ?string $mentionedBy, public string $where) {}

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
            ->title(($this->mentionedBy ?? 'Somebody').' mentioned you on '.$this->item->key)
            ->body($this->item->title.' — in '.$this->where)
            ->icon('heroicon-o-at-symbol')
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
            'work_item_mentioned',
            [
                'employee_name' => $notifiable->name ?? null,
                'key' => $this->item->key,
                'title' => $this->item->title,
                'mentioned_by' => $this->mentionedBy,
                'where' => $this->where,
                'company' => Company::current()?->name,
            ],
            subject: ($this->mentionedBy ?? 'Somebody').' mentioned you on '.$this->item->key,
            greeting: 'Hello '.($notifiable->name ?? '').',',
            lines: [
                ($this->mentionedBy ?? 'Somebody').' mentioned you in '.$this->where.' on '.$this->item->key.' — '.$this->item->title.'.',
            ],
        );
    }
}
