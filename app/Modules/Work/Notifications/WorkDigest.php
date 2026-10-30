<?php

namespace App\Modules\Work\Notifications;

use App\Modules\Core\Models\Company;
use App\Modules\Work\Filament\Pages\MyWork;
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
 * The morning digest — docs/project-management-plan.md §10.3.
 *
 * Three lists, each of keys and titles: due today, assigned since yesterday, blocked on you. One
 * notification per person who has anything in any of them, sent by `work:digest` at the time the
 * company sets, and never to somebody who has turned the *work* category off.
 */
class WorkDigest extends Notification implements CategorisedNotification, ShouldQueue
{
    use Queueable;

    /**
     * @param  array<int, string>  $dueToday  `KEY Title` lines
     * @param  array<int, string>  $assignedSinceYesterday
     * @param  array<int, string>  $blockedOnYou
     */
    public function __construct(
        public array $dueToday,
        public array $assignedSinceYesterday,
        public array $blockedOnYou,
    ) {}

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
            ->title('Your work today')
            ->body($this->summary())
            ->icon('heroicon-o-sun')
            ->info()
            ->actions([
                Action::make('open')->label('My Work')->url(MyWork::getUrl()),
            ])
            ->getDatabaseMessage();
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toDatabase($notifiable));
    }

    public function toMail(object $notifiable): MailMessage
    {
        $lines = [];

        foreach ([
            'Due today' => $this->dueToday,
            'Assigned to you since yesterday' => $this->assignedSinceYesterday,
            'Blocked on you' => $this->blockedOnYou,
        ] as $heading => $items) {
            if ($items === []) {
                continue;
            }

            $lines[] = '**'.$heading.'**';

            foreach ($items as $line) {
                $lines[] = '- '.$line;
            }
        }

        $mail = (new MailMessage)->action('Open My Work', MyWork::getUrl());

        return TemplatedMail::apply(
            $mail,
            'work_digest',
            [
                'employee_name' => $notifiable->name ?? null,
                'due_today' => implode("\n", $this->dueToday),
                'assigned_since_yesterday' => implode("\n", $this->assignedSinceYesterday),
                'blocked_on_you' => implode("\n", $this->blockedOnYou),
                'company' => Company::current()?->name,
            ],
            subject: 'Your work today: '.$this->summary(),
            greeting: 'Good morning '.($notifiable->name ?? '').',',
            lines: $lines,
        );
    }

    /** `2 due today · 1 newly assigned · 1 blocked on you`, with the empty parts left out. */
    public function summary(): string
    {
        $parts = array_filter([
            count($this->dueToday) > 0 ? count($this->dueToday).' due today' : null,
            count($this->assignedSinceYesterday) > 0 ? count($this->assignedSinceYesterday).' newly assigned' : null,
            count($this->blockedOnYou) > 0 ? count($this->blockedOnYou).' blocked on you' : null,
        ]);

        return implode(' · ', $parts) ?: 'nothing waiting';
    }
}
