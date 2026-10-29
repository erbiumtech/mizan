<?php

namespace App\Modules\Expenses\Notifications;

use App\Modules\Expenses\Models\ExpenseClaim;
use App\Support\Broadcasting;
use App\Support\Contracts\CategorisedNotification;
use App\Support\TemplatedMail;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ExpenseClaimSubmitted extends Notification implements CategorisedNotification, ShouldQueue
{
    use Queueable;

    public function __construct(public ExpenseClaim $claim) {}

    public function notificationCategory(): string
    {
        return 'approvals';
    }

    public function via(object $notifiable): array
    {
        return Broadcasting::channels(['mail', 'database', 'broadcast']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $employee = $this->employeeName();

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

        $mail->line('Approve it, or refuse it with a reason, from Expense Claims.');

        return TemplatedMail::wrap($mail, 'expense_claim_submitted', [
            'employee_name' => $employee,
            'approver_name' => $notifiable->name ?? null,
            'amount' => number_format((float) $this->claim->amount, 2),
            'description' => $this->claim->description,
            'claimed_on' => $this->claim->claimed_on->format('d M Y'),
            'company' => \App\Modules\Core\Models\Company::current()?->name,
        ]);
    }

    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title("Expense claim from {$this->employeeName()} awaits approval")
            ->body(number_format((float) $this->claim->amount, 2).' — '.$this->claim->description)
            ->getDatabaseMessage();
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toDatabase($notifiable));
    }

    private function employeeName(): string
    {
        return $this->claim->employee?->user?->name
            ?? $this->claim->employee?->employee_id
            ?? 'An employee';
    }
}
