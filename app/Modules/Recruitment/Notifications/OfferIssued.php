<?php

namespace App\Modules\Recruitment\Notifications;

use App\Modules\Core\Models\Company;
use App\Modules\Recruitment\Models\Offer;
use App\Modules\Recruitment\Services\OfferLetter;
use App\Support\TemplatedMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The offer, emailed to the candidate with the letter attached — docs/saas-launch-plan.md §7.2.
 *
 * Routed to an address: a candidate is not a user. Rendered when the mail is built, from the offer as it
 * stands then, for the reason OfferLetter stores nothing. Per-company wording through TemplatedMail under
 * the `offer_issued` key, like every customer-facing mail here.
 *
 * @param  array<string, mixed>  $input  the letter inputs the action collected (valid_days, signatory)
 */
class OfferIssued extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Offer $offer, public array $input = []) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $letters = app(OfferLetter::class);
        $data = $letters->data($this->offer, $this->input);
        $company = Company::current()?->name ?? $data['company']['legal_name'] ?? '';
        $name = $data['applicant']->name;

        $mail = TemplatedMail::apply(
            new MailMessage,
            'offer_issued',
            [
                'candidate_name' => $name,
                'position' => $data['position'],
                'joining_date' => $this->offer->joining_date->format('j M Y'),
                'valid_until' => $data['valid_until']->format('j M Y'),
                'company' => $company,
            ],
            subject: "Offer of employment: {$data['position']}".($company ? " at {$company}" : ''),
            greeting: "Dear {$name},",
            lines: [
                "We are pleased to offer you the position of {$data['position']}, joining on "
                    .$this->offer->joining_date->format('j F Y').'. The offer letter is attached with the full terms.',
                "The offer is valid until {$data['valid_until']->format('j F Y')}. Reply to this email to accept, or with any questions.",
            ],
        );

        return $mail->attachData(
            $letters->renderPdf($this->offer, $this->input)->raw(),
            $letters->filename($this->offer),
            ['mime' => 'application/pdf'],
        );
    }
}
