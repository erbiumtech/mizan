<?php

namespace App\Modules\Expenses\Notifications;

use App\Modules\Expenses\Models\ExpenseClaim;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the person who claimed what was decided — and, when refused, why.
 */
class ExpenseClaimDecided extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public ExpenseClaim $claim) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $amount = number_format((float) $this->claim->amount, 2);
        $refused = $this->claim->status === ExpenseClaim::STATUS_REFUSED;

        $mail = (new MailMessage)
            ->subject('Expense claim #'.$this->claim->id.' was '.($refused ? 'refused' : 'approved'))
            ->greeting("Hello {$notifiable->name},")
            ->line("Your claim for {$amount} — {$this->claim->description} — was "
                .($refused ? 'refused.' : 'approved.'));

        if ($refused) {
            return $mail->line('Reason: '.$this->claim->refusal_reason);
        }

        return $mail->line('It will be reimbursed with your next payslip.');
    }
}
