<?php

namespace App\Modules\Devices\Notifications;

use App\Support\Contracts\CategorisedNotification;
use App\Support\TemplatedMail;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

class DeviceSilent extends Notification implements CategorisedNotification
{
    use Queueable;

    public function __construct(
        public readonly string $deviceName,
        public readonly string $serialNo,
        public readonly ?Carbon $lastSeenAt,
    ) {}

    public function notificationCategory(): string
    {
        return 'monitoring';
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject("Attendance device {$this->deviceName} has gone quiet")
            ->line("{$this->deviceName} (serial {$this->serialNo}) last called in ".($this->lastSeenAt ? 'on '.$this->lastSeenAt->toDayDateTimeString() : 'never').'.')
            ->line('A quiet device looks exactly like a quiet office: until it calls again, nobody punching on it is being marked present.')
            ->line('Check its power and network, and that the cloud-server setting still points here.');

        return TemplatedMail::wrap($mail, 'device_silent', [
            'employee_name' => $notifiable->name ?? null,
            'device' => $this->deviceName,
            'serial' => $this->serialNo,
            'last_seen' => $this->lastSeenAt?->toDayDateTimeString(),
            'company' => \App\Modules\Core\Models\Company::current()?->name,
        ]);
    }

    public function toArray(object $notifiable): array
    {
        return ['device' => $this->deviceName, 'serial_no' => $this->serialNo, 'last_seen_at' => $this->lastSeenAt?->toIso8601String()];
    }
}
