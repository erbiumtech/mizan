<?php

namespace App\Notifications;

use App\Models\Payslip;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PayslipRejected extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Payslip $payslip)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $employeeName = $this->payslip->employee->user?->name
            ?? $this->payslip->employee->employee_id;

        return (new MailMessage)
            ->subject("Payslip rejected by {$employeeName} ({$this->payslip->month})")
            ->greeting("Hello {$notifiable->name},")
            ->line("{$employeeName} has rejected their {$this->payslip->month} payslip (net salary ".number_format((float) $this->payslip->net_salary, 2).' PKR).')
            ->line('Reason: '.($this->payslip->employee_rejection_reason ?: 'No reason given.'))
            ->action('Review Payslip', url("/cpi/resources/payslips/{$this->payslip->id}"))
            ->line('The rejection is advisory — correct the payslip or dismiss the objection as appropriate.');
    }
}
