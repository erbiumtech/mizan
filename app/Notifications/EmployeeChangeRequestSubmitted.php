<?php

namespace App\Notifications;

use App\Models\EmployeeChangeRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EmployeeChangeRequestSubmitted extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public EmployeeChangeRequest $changeRequest)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $employeeName = $this->changeRequest->employee->user?->name
            ?? $this->changeRequest->employee->employee_id;

        $mail = (new MailMessage)
            ->subject("Change request #{$this->changeRequest->id} from {$employeeName} awaits approval")
            ->greeting("Hello {$notifiable->name},")
            ->line("{$employeeName} has requested the following changes to their employee profile:");

        foreach ($this->changeRequest->requested_changes as $field => $value) {
            $original = $this->changeRequest->original_values[$field] ?? '—';
            $label = ucwords(str_replace(['user_', '_'], ['', ' '], $field));
            $mail->line("• {$label}: {$original} → {$value}");
        }

        return $mail
            ->action('Review Change Request', url("/cpi/resources/employee-change-requests/{$this->changeRequest->id}"))
            ->line('The change will only take effect once approved.');
    }
}
