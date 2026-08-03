<?php

namespace App\Support\WhatsApp;

/**
 * Employee phone numbers as WhatsApp needs them.
 *
 * They are stored as they are dialled locally — "0300-0000000", "+92 300 000
 * 0000", "0092-300-0000000" — and the API takes only E.164 digits. A number that
 * is merely passed through is accepted by the API and then silently delivered
 * nowhere, so this normalises rather than trusting the column.
 */
class PhoneNumber
{
    /**
     * E.164 digits, or null when there is nothing usable to send to.
     *
     * Null rather than a guess: a number too short to be real is reported to the
     * person sending, who can fix the employee record. Guessing at one is how a
     * payslip reaches a stranger.
     */
    public static function e164(?string $number, ?string $countryCode = null): ?string
    {
        $countryCode = preg_replace('/\D/', '', (string) ($countryCode ?? config('whatsapp.default_country_code')));

        $trimmed = trim((string) $number);

        if ($trimmed === '') {
            return null;
        }

        // Already international: keep the country code it carries.
        $isInternational = str_starts_with($trimmed, '+') || str_starts_with($trimmed, '00');

        $digits = preg_replace('/\D/', '', $trimmed);

        if ($digits === '') {
            return null;
        }

        if ($isInternational) {
            $digits = ltrim($digits, '0');
        } else {
            // A local number: drop the trunk 0 before prefixing, or the country
            // code would be followed by a zero no operator routes.
            $digits = $countryCode.ltrim($digits, '0');
        }

        // Shortest real E.164 subscriber numbers are 8 digits including the
        // country code; anything under that is a typo or an extension.
        return strlen($digits) >= 8 ? $digits : null;
    }
}
