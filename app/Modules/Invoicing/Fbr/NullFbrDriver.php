<?php

namespace App\Modules\Invoicing\Fbr;

use Illuminate\Support\Facades\Log;

/**
 * The driver that transmits nothing and records what it would have sent.
 *
 * Not a testing convenience (plan §3): it is how a company runs the whole
 * pipeline — queued submission, the submission log, the QR on the PDF, the
 * 72-hour void refusal — before its integrator registration and testing are
 * complete, which the regulation requires before going live.
 *
 * It answers "accepted" with a reference nobody can mistake for a real one
 * (`NULL-…`), because a dry run that answered anything else would leave half
 * the pipeline unrehearsed.
 */
final class NullFbrDriver implements FbrDriver
{
    public const NAME = 'null';

    public function name(): string
    {
        return self::NAME;
    }

    public function submit(array $payload, string $idempotencyKey): FbrResponse
    {
        // The FbrSubmission row is the durable record of what would have been
        // sent; this line is for whoever is tailing a log during the rehearsal.
        Log::info('FBR null driver: invoice would have been submitted', [
            'invoice_number' => $payload['invoice_number'] ?? null,
            'idempotency_key' => $idempotencyKey,
        ]);

        return FbrResponse::accepted(
            irn: 'NULL-'.$idempotencyKey,
            // A real integrator assigns the USIN; the dry run echoes our own number.
            usin: $payload['invoice_number'] ?? null,
            // What the QR really encodes is the integrator's to define (§9.5);
            // until then the synthetic IRN keeps the PDF path exercisable.
            qrPayload: 'NULL-'.$idempotencyKey,
        );
    }
}
