<?php

namespace App\Support\WhatsApp;

interface WhatsAppSender
{
    /**
     * Send a document to one number on WhatsApp.
     *
     * The document is handed over as {@see WhatsAppDocument} rather than as bytes
     * because the providers want opposite things — Meta uploads the file, Twilio
     * fetches a link — and the sender is the only place that knows which.
     *
     * @param  string  $to  E.164 number, no plus (see PhoneNumber::e164)
     * @return string the provider's message id, for the audit trail
     *
     * @throws WhatsAppException when the provider refuses or cannot be reached
     */
    public function sendDocument(string $to, WhatsAppDocument $document, string $caption): string;

    /**
     * Send a plain text message to one number — docs/saas-launch-plan.md §8.5.
     *
     * Free-form, which on both providers reaches only somebody who has messaged the
     * company's number in the last 24 hours, or anybody when the number is on a
     * sandbox. A field-visit confirmation goes to a person the rep has just sat
     * with, which is the one case where that window is usually open.
     *
     * @param  string  $to  E.164 number, no plus (see PhoneNumber::e164)
     * @return string the provider's message id, for the audit trail
     *
     * @throws WhatsAppException when the provider refuses or cannot be reached
     */
    public function sendText(string $to, string $text): string;
}
