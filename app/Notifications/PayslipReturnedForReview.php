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

/**
 * Your payslip has been changed — please look at it again.
 *
 * The counterpart to `PayslipObjectionAnswered`. That one says *the payslip stands*; this one says *you were
 * right, it has been corrected*, and asks for the acknowledgement afresh. Without it the employee would be
 * looking at a payslip that had quietly gone back to pending with nothing to say why.
 *
 * It carries the note payroll wrote, because "please review again" without what changed is a request nobody
 * can act on — and the same note is in the payslip's comment thread, where the rest of the exchange is.
 */
class PayslipReturnedForReview extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Payslip $payslip, public string $note) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database', 'broadcast'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $month = $this->payslip->month;

        return (new MailMessage)
            ->subject("Your {$month} payslip has been updated — please review it again")
            ->greeting("Hello {$notifiable->name},")
            ->line("You rejected your {$month} payslip. Payroll has looked at it and made a change.")
            ->line('What changed: '.$this->note)
            ->line('Net salary is now '.number_format((float) $this->payslip->net_salary, 2).' PKR.')
            ->action('Open the payslip', url("/cpi/resources/payslips/{$this->payslip->id}"))
            ->line('Please accept it if it is right now, or reject it again and say what is still wrong.');
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title("Your {$this->payslip->month} payslip has been updated")
            ->body($this->note)
            ->info()
            ->actions([
                Action::make('open')
                    ->label('Review it')
                    ->url("/cpi/resources/payslips/{$this->payslip->id}"),
            ])
            ->getDatabaseMessage();
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toDatabase($notifiable));
    }
}
