<?php

namespace App\Notifications;

use App\Modules\Payroll\Models\Payslip;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
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
        return ['mail', 'database', 'broadcast'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $employeeName = $this->employeeName();

        return (new MailMessage)
            ->subject("Payslip rejected by {$employeeName} ({$this->payslip->month})")
            ->greeting("Hello {$notifiable->name},")
            ->line("{$employeeName} has rejected their {$this->payslip->month} payslip (net salary ".number_format((float) $this->payslip->net_salary, 2).' PKR).')
            ->line('Reason: '.($this->payslip->employee_rejection_reason ?: 'No reason given.'))
            ->action('Review Payslip', url("/cpi/resources/payslips/{$this->payslip->id}"))
            ->line('The rejection is advisory — correct the payslip or dismiss the objection as appropriate.');
    }

    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title("Payslip rejected by {$this->employeeName()}")
            ->body("{$this->payslip->month} payslip — net ".number_format((float) $this->payslip->net_salary, 2).' PKR.')
            ->danger()
            ->actions([
                Action::make('review')
                    ->label('Review')
                    ->url("/cpi/resources/payslips/{$this->payslip->id}"),
            ])
            ->getDatabaseMessage();
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toDatabase($notifiable));
    }

    private function employeeName(): string
    {
        return $this->payslip->employee->user?->name
            ?? $this->payslip->employee->employee_id;
    }
}
