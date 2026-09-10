<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

/**
 * The short-lived link `whatsapp:test` gives a provider to fetch its test document from.
 *
 * Twilio sends media by link rather than by upload, so a connectivity test has nothing to send
 * unless the document is reachable over HTTP. Without this the command could only ever test the
 * Meta Cloud driver, and the one it could not test is the one in production.
 *
 * **Signed in the path, not the query string**, for the reason {@see \App\Modules\Payroll\Support\PayslipMediaLink}
 * sets out at length: Twilio's Content Templates substitute a variable into the URL and support
 * variables only after the domain, so a `?expires=…&signature=…` can come back percent-encoded and
 * verify as a forgery. All anybody then sees is Twilio error 11200. A path-only link cannot be
 * mangled that way.
 *
 * What it serves is a generated test page with no data in it, so the exposure of a valid link is a
 * blank PDF. The signature and the expiry are there to stop it becoming a permanent unauthenticated
 * PDF renderer on the host.
 */
class DeliveryTestLink
{
    public const FILENAME = 'connection-test.pdf';

    /** The absolute URL, valid for the given number of minutes. */
    public static function for(int $minutes = 15, string $filename = self::FILENAME): string
    {
        $expires = now()->addMinutes($minutes)->getTimestamp();

        return URL::to("delivery-test/{$expires}/".self::signature($expires, $filename)."/{$filename}");
    }

    public static function isValid(int $expires, string $signature, string $filename): bool
    {
        if (Carbon::createFromTimestamp($expires)->isPast()) {
            return false;
        }

        return hash_equals(self::signature($expires, $filename), $signature);
    }

    private static function signature(int $expires, string $filename): string
    {
        return hash_hmac('sha256', implode('|', [$expires, $filename]), (string) config('app.key'));
    }
}
