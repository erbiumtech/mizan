<?php

namespace App\Support\WhatsApp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Twilio's WhatsApp API, following
 * https://www.twilio.com/docs/whatsapp/guidance-whatsapp-media-messages.
 *
 * One call — POST .../Messages.json — but the file does not travel in it. Twilio
 * takes a `MediaUrl` and fetches the document itself, which is the whole shape of
 * this driver and the reason for three decisions:
 *
 *  - **The URL must be reachable by Twilio, and a payslip must not be public.**
 *    It is therefore a signed URL with a short expiry (see the media route in
 *    Payroll's routes): unguessable, tamper-evident, and dead within minutes of
 *    being handed over.
 *  - **Twilio validates the content-type at that URL.** "If the content-type
 *    header does not match that of the media file, Twilio rejects the request" —
 *    so the route serves `application/pdf` explicitly rather than letting a
 *    framework default decide.
 *  - **"Twilio does not support setting a filename or caption for documents."**
 *    Both are honoured by Meta and neither can be here, so the filename is put in
 *    the last segment of the media URL — which is what the recipient's client
 *    shows — and the caption travels as body text.
 *
 * Two ways to send, and which one applies is not a preference. WhatsApp only lets
 * a business open a conversation with an approved template, so a configured
 * `content_sid` (see templateParameters) is the path any real payroll takes. The
 * freeform Body + MediaUrl below is a 24-hour-window message: it works against the
 * Twilio sandbox and answers 63016 on a production number.
 *
 * PDFs are supported media, up to 20MB. A payslip is a few tens of kilobytes, so
 * the limit only matters if this driver is ever reused for something bigger; it is
 * checked rather than assumed, because Twilio's refusal for an oversized file
 * arrives as a generic error.
 */
class TwilioWhatsAppSender implements WhatsAppSender
{
    /** Twilio's documented ceiling for non-image media. */
    public const MAX_BYTES = 20 * 1024 * 1024;

    public function __construct(
        private string $accountSid,
        private string $authToken,
        private string $from,
        private string $apiBase = 'https://api.twilio.com/2010-04-01',
        private ?string $contentSid = null,
        private ?string $templateMediaBase = null,
    ) {}

    public function sendDocument(string $to, WhatsAppDocument $document, string $caption): string
    {
        if (! $document->hasUrl()) {
            throw new WhatsAppException(
                "Twilio sends media by link and {$document->filename} has no URL to serve it from."
            );
        }

        $response = $this->post([
            // Both numbers carry the channel prefix; a bare +number on this API is
            // an SMS, which would silently send the wrong thing over the wrong
            // channel and bill for it.
            'From' => $this->channel($this->from),
            'To' => $this->channel($to),
            ...($this->contentSid
                ? $this->templateParameters($document, $caption)
                : $this->freeformParameters($document, $caption)),
        ]);

        $sid = $response->json('sid');

        if (! is_string($sid) || $sid === '') {
            throw new WhatsAppException(
                'Twilio accepted the request but returned no message sid: '.$response->body()
            );
        }

        return $sid;
    }

    /**
     * An approved Content Template, which is the only way to reach an employee who
     * has not messaged you first — and therefore the only way payroll ever reaches
     * anybody.
     *
     * ContentSid is mutually exclusive with Body and MediaUrl ("Cannot be combined
     * with: Body or MediaUrl"), so neither is sent here. The document travels as a
     * variable substituted into the media URL the template already holds, and the
     * caption as the body variable.
     *
     * The template this expects, created once through the Content API:
     *
     *     {
     *       "friendly_name": "payslip_issued",
     *       "language": "en",
     *       "variables": {"1": "whatsapp-media/…/payslip.pdf", "2": "Payslip for July 2026"},
     *       "types": {
     *         "twilio/media": {
     *           "body": "{{2}} — please open it and confirm the figures.",
     *           "media": ["https://payroll.example.com/{{1}}"]
     *         }
     *       }
     *     }
     *
     * `media` holds the domain and `{{1}}` takes everything after it, because
     * "variables are only supported after the domain". That domain is configured
     * here as well (`template_media_base`) so the two can be checked against each
     * other — a template pointing at one host while the app generates links on
     * another is otherwise invisible until Twilio reports error 11200.
     *
     * @return array<string, string>
     */
    protected function templateParameters(WhatsAppDocument $document, string $caption): array
    {
        return [
            'ContentSid' => $this->contentSid,
            // A JSON string keyed by variable number as strings, per the API.
            'ContentVariables' => json_encode([
                '1' => $this->templatePathFor($document),
                '2' => $caption,
            ]),
        ];
    }

    /** @return array<string, string> */
    protected function freeformParameters(WhatsAppDocument $document, string $caption): array
    {
        return [
            'Body' => $caption,
            'MediaUrl' => $document->url(),
        ];
    }

    /**
     * What goes into `{{1}}`: the link minus the domain the template already holds.
     *
     * Refused rather than guessed when the two disagree. Handing Twilio a variable
     * that does not belong to the template's domain produces a URL like
     * https://payroll.example.com/https://localhost/whatsapp-media/… which it
     * cannot fetch, and the only symptom is a message that never arrives.
     */
    protected function templatePathFor(WhatsAppDocument $document): string
    {
        $url = $document->url();

        if (blank($this->templateMediaBase)) {
            throw new WhatsAppException(
                'Sending through a Twilio Content Template needs the template\'s media domain '
                .'configured (whatsapp.twilio.template_media_base), so the link can be passed as the variable after it.'
            );
        }

        $base = rtrim($this->templateMediaBase, '/').'/';

        if (! str_starts_with($url, $base)) {
            throw new WhatsAppException(
                "The payslip link ({$url}) does not sit under the media domain the Twilio template holds ({$base}). "
                .'Twilio would fetch the two concatenated. Point APP_URL and template_media_base at the same host.'
            );
        }

        return substr($url, strlen($base));
    }

    /**
     * Guards a file Twilio would refuse. Callers hand over a closure, so this is
     * the first point the size is actually known.
     */
    public static function assertSendable(WhatsAppDocument $document): void
    {
        $bytes = strlen($document->bytes());

        if ($bytes > self::MAX_BYTES) {
            throw new WhatsAppException(sprintf(
                '%s is %.1fMB; WhatsApp media through Twilio is capped at 20MB.',
                $document->filename,
                $bytes / 1024 / 1024,
            ));
        }
    }

    /**
     * `whatsapp:+923001234567` — the channel prefix Twilio routes on, added here
     * so nothing else in the app has to know about it. Already-prefixed values are
     * left alone, since that is how the `from` is usually configured.
     */
    protected function channel(string $number): string
    {
        if (str_starts_with($number, 'whatsapp:')) {
            return $number;
        }

        return 'whatsapp:'.(str_starts_with($number, '+') ? $number : '+'.$number);
    }

    /** @param array<string, string> $payload */
    protected function post(array $payload): Response
    {
        $url = "{$this->apiBase}/Accounts/{$this->accountSid}/Messages.json";

        try {
            // Form-encoded, not JSON: this is Twilio's classic REST API.
            $response = Http::withBasicAuth($this->accountSid, $this->authToken)
                ->asForm()
                ->post($url, $payload);
        } catch (ConnectionException $e) {
            throw new WhatsAppException('Twilio could not be reached: '.$e->getMessage(), previous: $e);
        }

        if ($response->successful()) {
            return $response;
        }

        // Twilio's own words and its error code. 63016 (outside the 24-hour
        // window, no template), 63003 (unreachable recipient) and 11200 (Twilio
        // could not fetch the MediaUrl) need three unrelated fixes, and none of
        // them can be worked out from a status code.
        $message = $response->json('message') ?: $response->body();
        $code = $response->json('code');

        throw new WhatsAppException(
            "Twilio refused the message: {$message}".($code ? " (code {$code})" : '')
        );
    }
}
