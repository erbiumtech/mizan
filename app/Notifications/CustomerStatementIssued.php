<?php

namespace App\Notifications;

use App\Modules\Core\Models\Company;
use App\Modules\Invoicing\Models\Contact;
use App\Modules\Invoicing\Services\CustomerStatement;
use App\Support\TemplatedMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * A customer's statement of account, with the PDF attached — `docs/erpnext-gap-plan.md` §4 item 1.
 *
 * Routed on demand to the contact's correspondence address, so mail is the only channel; the wording is
 * the company's own where it has written one (`EmailTemplate` key `customer_statement`).
 */
class CustomerStatementIssued extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Contact $contact, public string $from, public string $to) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $statements = app(CustomerStatement::class);
        $statement = $statements->for($this->contact, $this->from, $this->to);

        $period = Carbon::parse($this->from)->format('j M Y').' to '.Carbon::parse($this->to)->format('j M Y');
        $balance = number_format($statement['closing'], 2);
        $company = Company::current()?->name;

        $mail = TemplatedMail::apply(
            new MailMessage,
            'customer_statement',
            [
                'contact_name' => $this->contact->name,
                'period' => $period,
                'balance' => $balance,
                'company' => $company,
            ],
            subject: "Your statement of account, {$period}",
            greeting: "Hello {$this->contact->name},",
            lines: [
                "Your statement of account for {$period} is attached.",
                $statement['closing'] > 0.004
                    ? "The balance on your account is {$balance}."
                    : 'Nothing is outstanding on your account.',
                'If your records disagree with it, reply to this email quoting the document reference and we will look into it.',
            ],
        );

        return $mail->attachData(
            $statements->renderPdf($this->contact, $this->from, $this->to)->raw(),
            $statements->filename($this->contact, $this->to),
            ['mime' => 'application/pdf'],
        );
    }
}
