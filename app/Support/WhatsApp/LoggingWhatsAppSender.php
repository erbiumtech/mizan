<?php

namespace App\Support\WhatsApp;

use App\Modules\Core\Models\MessageLog;
use Throwable;

/**
 * Every WhatsApp message in the log — docs/saas-launch-plan.md §14.7.
 *
 * A decorator around whichever sender the environment configured, so the three
 * drivers and the log fake all record the same way and none of them has to
 * remember to. A failure is logged as a failure and rethrown: the caller still
 * decides what a refusal means.
 */
class LoggingWhatsAppSender implements WhatsAppSender
{
    public function __construct(private readonly WhatsAppSender $inner) {}

    public function sendDocument(string $to, WhatsAppDocument $document, string $caption): string
    {
        return $this->logged($to, $document->filename, fn (): string => $this->inner->sendDocument($to, $document, $caption));
    }

    public function sendText(string $to, string $text): string
    {
        return $this->logged($to, \Illuminate\Support\Str::limit($text, 80), fn (): string => $this->inner->sendText($to, $text));
    }

    private function logged(string $to, string $subject, callable $send): string
    {
        try {
            $reference = $send();
        } catch (Throwable $e) {
            MessageLog::record([
                'channel' => MessageLog::CHANNEL_WHATSAPP,
                'recipient' => $to,
                'subject' => $subject,
                'status' => MessageLog::STATUS_FAILED,
                'error' => \Illuminate\Support\Str::limit($e->getMessage(), 500),
            ]);

            throw $e;
        }

        MessageLog::record([
            'channel' => MessageLog::CHANNEL_WHATSAPP,
            'recipient' => $to,
            'subject' => $subject,
            'reference' => $reference,
        ]);

        return $reference;
    }

    /** The sender underneath, for a caller that needs to know which driver it got. */
    public function inner(): WhatsAppSender
    {
        return $this->inner;
    }
}
