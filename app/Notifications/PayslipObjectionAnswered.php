<?php

namespace App\Notifications;

use App\Modules\Payroll\Models\Payslip;
use App\Support\Broadcasting;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * What payroll said about the payslip you rejected.
 *
 * The mirror of `PayslipRejected`, which tells the payroll team an employee has objected. This one closes
 * the loop: an objection that is answered in a database column and nowhere else is an objection the person
 * who raised it never hears about, and they will raise it again next month.
 *
 * **It carries the last reply and the original objection together**, because the employee wrote the
 * objection a fortnight ago and the answer is only meaningful beside it. The reply is read out of the
 * payslip's comment thread, which is where the conversation happened.
 *
 * It does not pretend to be an acceptance. The subject says the payslip stands and the salary has been
 * released; whether the employee agrees is a conversation, and the comment thread on the payslip is where
 * that belongs.
 */
class PayslipObjectionAnswered extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Payslip $payslip) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return Broadcasting::channels(['mail', 'database', 'broadcast']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $month = $this->payslip->month;

        return (new MailMessage)
            ->subject("Your objection to the {$month} payslip has been answered")
            ->greeting("Hello {$notifiable->name},")
            ->line("You rejected your {$month} payslip, and payroll has now answered.")
            ->line('You said: '.($this->payslip->employee_rejection_reason ?: 'No reason was recorded.'))
            ->line('They replied: '.$this->reply())
            ->line('The payslip stands as it is and the salary has been released for payment.')
            ->action('Open the payslip', url("/cpi/resources/payslips/{$this->payslip->id}"))
            ->line('If you still think something is wrong, reply to payroll or add a comment on the payslip.');
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title("Your {$this->payslip->month} objection has been answered")
            ->body($this->reply())
            ->info()
            ->actions([
                Action::make('open')
                    ->label('Open')
                    ->url("/cpi/resources/payslips/{$this->payslip->id}"),
            ])
            ->getDatabaseMessage();
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toDatabase($notifiable));
    }

    /**
     * The last thing said in the thread, which is the answer.
     *
     * Read from the conversation rather than from a column on the payslip, because the conversation is where
     * it was written — see `Payslip::resolveObjection()`. Falls back to a plain sentence rather than to an
     * empty line: a decision was still taken, and the employee is entitled to know it was.
     */
    private function reply(): string
    {
        return trim((string) $this->payslip->latestObjectionReply()?->body)
            ?: 'The objection was closed without a further comment.';
    }
}
