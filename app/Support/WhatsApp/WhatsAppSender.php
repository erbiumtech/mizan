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
}
