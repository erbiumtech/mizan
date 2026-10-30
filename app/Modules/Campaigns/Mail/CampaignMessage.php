<?php

namespace App\Modules\Campaigns\Mail;

use App\Support\BrandKit;
use App\Support\CompanyLetterhead;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * One campaign email to one recipient — docs/crms-plan.md §6.
 *
 * A Mailable, not a notification: the words are the campaign's, written by the company for this send,
 * so there is no template for TemplatedMail to swap in, and the recipient is a lead or a customer rather
 * than somebody with notification preferences — consent is what governs them, checked before this is
 * built. Sent by the DeliverCampaign job one recipient at a time, so a refusal is recorded against that
 * recipient instead of failing the batch.
 */
class CampaignMessage extends Mailable
{
    use Queueable;

    public function __construct(
        public string $subjectLine,
        public string $body,
    ) {}

    public function envelope(): Envelope
    {
        $replyTo = CompanyLetterhead::data()['email'];

        return new Envelope(
            subject: $this->subjectLine,
            replyTo: $replyTo !== '' ? [new Address($replyTo, CompanyLetterhead::displayName())] : [],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'campaigns.message',
            text: 'campaigns.message-text',
            with: [
                'paragraphs' => self::paragraphs($this->body),
                'company' => CompanyLetterhead::displayName(),
                'brand' => BrandKit::forTemplate(),
            ],
        );
    }

    /** Plain text as typed: a blank line starts a paragraph. @return array<int, string> */
    public static function paragraphs(string $body): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R{2,}/', trim($body)) ?: []), fn (string $p): bool => $p !== ''));
    }
}
