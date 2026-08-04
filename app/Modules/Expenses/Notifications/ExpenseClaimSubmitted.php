<?php

namespace App\Modules\Expenses\Notifications;

use App\Modules\Expenses\Models\ExpenseClaim;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ExpenseClaimSubmitted extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public ExpenseClaim $claim) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $employee = $this->claim->employee?->user?->name
            ?? $this->claim->employee?->employee_id
            ?? 'An employee';

        $mail = (new MailMessage)
            ->subject("Expense claim #{$this->claim->id} from {$employee} awaits approval")
            ->greeting("Hello {$notifiable->name},")
            ->line("{$employee} is claiming ".number_format((float) $this->claim->amount, 2).'.')
            ->line('For: '.$this->claim->description)
            ->line('Spent on: '.$this->claim->claimed_on->format('d M Y'));

        if ($this->claim->transactionType) {
            $mail->line('Category: '.$this->claim->transactionType->name);
        }

        // The receipt is named but not attached: it is reachable only to somebody
        // signed in with access to this company, which an email is not.
        $mail->line($this->claim->receipt_path
            ? 'A receipt was attached — open the claim to see it.'
            : 'No receipt was attached.');

        return $mail->line('Approve it, or refuse it with a reason, from Expense Claims.');
    }
}
