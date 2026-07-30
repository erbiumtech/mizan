<?php

namespace App\Notifications;

use App\Modules\Employees\Models\EmployeeChangeRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EmployeeChangeRequestSubmitted extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public EmployeeChangeRequest $changeRequest) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $employeeName = $this->changeRequest->employee->user?->name
            ?? $this->changeRequest->employee->employee_id;

        $target = $this->changeRequest->targetsSetting()
            ? 'their salary settings'
            : 'their employee profile';

        $mail = (new MailMessage)
            ->subject("Change request #{$this->changeRequest->id} from {$employeeName} awaits approval")
            ->greeting("Hello {$notifiable->name},")
            ->line("{$employeeName} has requested the following changes to {$target}:");

        $labels = ['nic_front' => 'NIC (Front)', 'nic_back' => 'NIC (Back)'];

        foreach ($this->changeRequest->requested_changes as $field => $value) {
            $label = $labels[$field] ?? ucwords(str_replace(['user_', '_'], ['', ' '], $field));

            // Uploads are stored paths; the filename means nothing in an email,
            // and the file itself is only reachable to a signed-in approver.
            if (in_array($field, EmployeeChangeRequest::IMAGE_FIELDS, true)) {
                $mail->line("• {$label}: a new scan was uploaded — open the request to compare it.");

                continue;
            }

            $original = $this->changeRequest->original_values[$field] ?? '—';
            $mail->line("• {$label}: {$original} → {$value}");
        }

        return $mail
            ->action('Review Change Request', url("/cpi/resources/employee-change-requests/{$this->changeRequest->id}"))
            ->line('The change will only take effect once approved.');
    }
}
