<?php

namespace App\Support\WhatsApp;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The default sender: writes what would have been sent and reports success.
 *
 * What local development and the test suite run on. It is also what an install
 * with no credentials gets, so payroll can send payslips by email while WhatsApp
 * is still waiting on a template approval, and the log says exactly what would
 * have gone where.
 */
class LogWhatsAppSender implements WhatsAppSender
{
    public function sendDocument(string $to, WhatsAppDocument $document, string $caption): string
    {
        $id = 'log-'.Str::random(16);

        Log::info('WhatsApp document (log driver, nothing sent)', [
            'to' => $to,
            'filename' => $document->filename,
            'caption' => $caption,
            // Rendered here so the log driver exercises the same work the real
            // ones do: a payslip that cannot be rendered must fail in development
            // too, not only once credentials are in place.
            'bytes' => strlen($document->bytes()),
            'url' => $document->hasUrl() ? $document->url() : null,
            'message_id' => $id,
        ]);

        return $id;
    }
}
