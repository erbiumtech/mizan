<?php

namespace App\Notifications;

use App\Modules\Payroll\Models\Payslip;
use App\Support\Broadcasting;
use App\Support\CompanyCurrency;
use App\Support\Contracts\CategorisedNotification;
use App\Support\TemplatedMail;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PayslipRejected extends Notification implements CategorisedNotification, ShouldQueue
{
    use Queueable;

    public function __construct(public Payslip $payslip) {}

    public function notificationCategory(): string
    {
        return 'payroll';
    }

    public function via(object $notifiable): array
    {
        return Broadcasting::channels(['mail', 'database', 'broadcast']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $employeeName = $this->employeeName();

        $mail = (new MailMessage)
            ->subject("Payslip rejected by {$employeeName} ({$this->payslip->month})")
            ->greeting("Hello {$notifiable->name},")
            ->line("{$employeeName} has rejected their {$this->payslip->month} payslip (net salary ".number_format((float) $this->payslip->net_salary, 2).' '.CompanyCurrency::code().').')
            ->line('Reason: '.($this->payslip->employee_rejection_reason ?: 'No reason given.'))
            ->action('Review Payslip', url("/cpi/resources/payslips/{$this->payslip->id}"))
            ->line('The rejection is advisory — correct the payslip or dismiss the objection as appropriate.');

        return TemplatedMail::wrap($mail, 'payslip_rejected', [
            'employee_name' => $employeeName,
            'period' => $this->payslip->month,
            'reason' => $this->payslip->employee_rejection_reason,
            'company' => \App\Modules\Core\Models\Company::current()?->name,
        ]);
    }

    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title("Payslip rejected by {$this->employeeName()}")
            ->body("{$this->payslip->month} payslip — net ".number_format((float) $this->payslip->net_salary, 2).' '.CompanyCurrency::code().'.')
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
