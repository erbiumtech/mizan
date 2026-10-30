<?php

namespace App\Support\WhatsApp;

/**
 * A sender that can deliver an approved template by name — what a campaign needs.
 *
 * Separate from WhatsAppSender rather than a method added to it: that interface has fakes in the test
 * suite and in other modules, and a provider (or a fake) that cannot send templates should say so by not
 * implementing this, not by throwing from a method it was forced to have. Campaigns check for it and record
 * the recipient as failed, with that reason, when the configured provider cannot — never falling back to
 * free text, which the providers refuse outside a 24-hour window and which puts the number at risk.
 */
interface SendsTemplates
{
    /**
     * @param  string  $to  E.164 number, no plus (see PhoneNumber::e164)
     * @param  string  $template  the approved template — Meta's template name, Twilio's Content SID
     * @param  array<int, string>  $parameters  the body variables, {{1}} first
     * @return string the provider's message id
     *
     * @throws WhatsAppException when the provider refuses or cannot be reached
     */
    public function sendTemplate(string $to, string $template, array $parameters = []): string;
}
