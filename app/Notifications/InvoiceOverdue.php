<?php

namespace App\Notifications;

use App\Modules\Core\Models\Company;
use App\Modules\Invoicing\Models\Invoice;
use App\Support\TemplatedMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A reminder to a customer that an invoice is past due — `docs/erpnext-gap-plan.md` Phase 5.
 *
 * **Mail only, and to somebody with no account here.** Every other notification in this directory reaches a
 * user; this one is routed to an address on a `Contact`, so there is no database channel to write to and no
 * broadcast to make. `via()` says so rather than relying on the notifiable to have no such channel.
 *
 * **The letter, and not the interest.** ERPNext's Dunning books an interest charge and a fee through the
 * payment's deductions, which is a posting decision — what rate, from what date, to which income account,
 * and whether a customer who pays the invoice but not the interest is then in arrears again. The gap plan's
 * §4 says to take the reminder and leave the interest until somebody actually charges it, and this class is
 * that line: nothing here posts anything.
 *
 * **One invoice per email, deliberately.** A single message listing everything a customer owes is a
 * statement of account, which needs a per-recipient rendered document and is named as its own item in §4.
 * A reminder about one overdue invoice is a thing somebody can act on without a reconciliation.
 */
class InvoiceOverdue extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Invoice $invoice, public int $daysOverdue) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $contact = $this->invoice->contact?->name ?? 'there';
        $number = $this->invoice->invoice_number;
        $amount = number_format($this->invoice->outstanding(), 2);
        $due = $this->invoice->due_date?->format('j M Y') ?? '—';
        $company = Company::current()?->name;

        // Neutral on purpose. A first reminder that opens by threatening is a first reminder that gets a
        // customer's lawyer rather than a payment, and a company that wants a harder letter writes one —
        // `EmailTemplate` key `invoice_overdue`.
        return TemplatedMail::apply(
            new MailMessage,
            'invoice_overdue',
            [
                'contact_name' => $contact,
                'invoice_number' => $number,
                'amount' => $amount,
                'due_date' => $due,
                'days_overdue' => (string) $this->daysOverdue,
                'company' => $company,
            ],
            subject: "Reminder: invoice {$number} is overdue",
            greeting: "Hello {$contact},",
            lines: [
                "Invoice {$number} was due on {$due} and {$amount} of it is still outstanding — "
                    ."{$this->daysOverdue} days ago.",
                'If it has been paid in the last few days, thank you — this will have crossed with it, and '
                    .'you can ignore it.',
                'If something is holding it up, tell us what and we will sort it out.',
            ],
        );
    }
}
