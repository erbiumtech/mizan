<?php

namespace App\Notifications;

use App\Modules\Core\Models\ContactMessage;
use App\Support\Contracts\CategorisedNotification;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use Spatie\Multitenancy\Jobs\NotTenantAware;

/**
 * A visitor wrote through the public contact form — docs/company-website-plan.md W4.
 *
 * To every active super admin, by mail and in the platform panel's bell. The message body is
 * quoted in the mail so the reader can answer from their inbox without opening the panel; the
 * panel is where it is marked handled.
 *
 * `NotTenantAware`: sent from a public page with no tenant current, like SignupVerification.
 */
class ContactMessageReceived extends Notification implements CategorisedNotification, NotTenantAware, ShouldQueue
{
    use Queueable;

    public function __construct(public ContactMessage $message) {}

    public function notificationCategory(): string
    {
        return 'account';
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $from = $this->message->company
            ? "{$this->message->name} ({$this->message->company})"
            : $this->message->name;

        return (new MailMessage)
            ->subject("Website message from {$from}")
            ->greeting("Hello {$notifiable->name},")
            ->line("{$from} <{$this->message->email}> wrote through the website:")
            ->line('"'.$this->message->message.'"')
            ->action('Open in the platform panel', url('/platform/contact-messages'))
            ->line('Reply to them directly; mark the message handled in the panel when you have.');
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title("Website message from {$this->message->name}")
            ->body(Str::limit($this->message->message, 140))
            ->actions([
                Action::make('open')
                    ->label('Open')
                    ->url('/platform/contact-messages'),
            ])
            ->getDatabaseMessage();
    }
}
