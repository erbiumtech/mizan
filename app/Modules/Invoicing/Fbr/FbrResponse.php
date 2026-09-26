<?php

namespace App\Modules\Invoicing\Fbr;

use App\Modules\Invoicing\Models\FbrSubmission;

/**
 * What a driver came back with — one of the three outcomes an FbrSubmission
 * row records, plus the fields that outcome carries.
 *
 * A value, not an exception, for rejections AND transport errors: the job has
 * to write the submission row for all three outcomes before it decides
 * anything, and only it knows that an `error` is the one worth retrying (a
 * rejection is FBR saying no — resending identical data is not a fix).
 */
final class FbrResponse
{
    private function __construct(
        /** One of FbrSubmission::OUTCOME_*. */
        public readonly string $outcome,
        public readonly ?string $irn = null,
        public readonly ?string $usin = null,
        /**
         * What the invoice QR must encode, as the integrator defines it —
         * stored on the invoice so a reprint carries the QR it was reported
         * with (plan §4). Null lets the job fall back to the IRN.
         */
        public readonly ?string $qrPayload = null,
        public readonly ?int $httpStatus = null,
        public readonly ?array $responsePayload = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
    ) {}

    public static function accepted(
        string $irn,
        ?string $usin = null,
        ?string $qrPayload = null,
        ?int $httpStatus = null,
        ?array $responsePayload = null,
    ): self {
        return new self(FbrSubmission::OUTCOME_ACCEPTED, $irn, $usin, $qrPayload, $httpStatus, $responsePayload);
    }

    public static function rejected(
        ?string $errorCode,
        string $errorMessage,
        ?int $httpStatus = null,
        ?array $responsePayload = null,
    ): self {
        return new self(
            FbrSubmission::OUTCOME_REJECTED,
            httpStatus: $httpStatus,
            responsePayload: $responsePayload,
            errorCode: $errorCode,
            errorMessage: $errorMessage,
        );
    }

    /** We never heard back — a timeout, a 5xx. May have landed anyway; only a retry with the same idempotency key is safe. */
    public static function error(string $errorMessage, ?int $httpStatus = null, ?string $errorCode = null): self
    {
        return new self(
            FbrSubmission::OUTCOME_ERROR,
            httpStatus: $httpStatus,
            errorCode: $errorCode,
            errorMessage: $errorMessage,
        );
    }
}
