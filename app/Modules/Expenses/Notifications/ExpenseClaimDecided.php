<?php

namespace App\Modules\Expenses\Notifications;

use App\Modules\Expenses\Models\ExpenseClaim;
use App\Support\TemplatedMail;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
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
        return ['mail', 'database', 'broadcast'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $amount = number_format((float) $this->claim->amount, 2);
        $refused = $this->claim->status === ExpenseClaim::STATUS_REFUSED;

        $lines = [
            "Your claim for {$amount} — {$this->claim->description} — was "
                .($refused ? 'refused.' : 'approved.'),
            $refused
                ? 'Reason: '.$this->claim->refusal_reason
                : 'It will be reimbursed with your next payslip.',
        ];

        return TemplatedMail::apply(
            new MailMessage,
            'expense_claim_decided',
            [
                'employee_name' => $notifiable->name,
                'amount' => $amount,
                'description' => $this->claim->description,
                'decision' => $refused ? 'refused' : 'approved',
                'reason' => $this->claim->refusal_reason,
                'company' => \App\Modules\Core\Models\Company::current()?->name,
            ],
            subject: 'Expense claim #'.$this->claim->id.' was '.($refused ? 'refused' : 'approved'),
            greeting: "Hello {$notifiable->name},",
            lines: $lines,
        );
    }

    public function toDatabase(object $notifiable): array
    {
        $amount = number_format((float) $this->claim->amount, 2);
        $refused = $this->claim->status === ExpenseClaim::STATUS_REFUSED;

        $notification = FilamentNotification::make()
            ->title('Expense claim '.($refused ? 'refused' : 'approved'))
            ->body("Your claim for {$amount} — {$this->claim->description}"
                .($refused ? ' was refused: '.$this->claim->refusal_reason : ' will be reimbursed with your next payslip.'));

        $refused ? $notification->danger() : $notification->success();

        return $notification->getDatabaseMessage();
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toDatabase($notifiable));
    }
}
