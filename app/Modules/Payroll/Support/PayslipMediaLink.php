<?php

namespace App\Modules\Payroll\Support;

use App\Modules\Core\Models\Company;
use App\Modules\Payroll\Models\Payslip;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

/**
 * The short-lived link a WhatsApp provider fetches a payslip PDF from.
 *
 * Signed by hand, in the path, rather than with Laravel's signed URLs — and that
 * is the whole reason this class exists. Twilio's Content Templates hold the media
 * URL in the template with a variable substituted into it, and the documentation
 * is explicit that "variables are only supported after the domain". A Laravel
 * signed URL carries `?expires=…&signature=…`, so passing one as a template
 * variable means hoping the substitution leaves a query string intact; if it
 * percent-encodes the `?`, the signature no longer verifies and all anybody sees
 * is Twilio error 11200, "could not fetch the media".
 *
 * A path-only link cannot be mangled that way: every part of it is an ordinary
 * path segment. The signature covers the company, the payslip, the expiry and the
 * filename together, so none of them can be edited to fetch anything else.
 */
class PayslipMediaLink
{
    /** The absolute URL, valid for the given number of minutes. */
    public static function for(Payslip $payslip, Company $company, string $filename, int $minutes): string
    {
        $expires = now()->addMinutes($minutes)->getTimestamp();

        return URL::to(self::path($company->slug, $payslip->getKey(), $expires, $filename));
    }

    /** The same link without its scheme and host — what a Twilio template variable takes. */
    public static function pathFor(Payslip $payslip, Company $company, string $filename, int $minutes): string
    {
        return ltrim(
            (string) parse_url(self::for($payslip, $company, $filename, $minutes), PHP_URL_PATH),
            '/',
        );
    }

    /**
     * Is this link genuine and still alive?
     *
     * Expiry is checked before the signature so an old link reads as expired
     * rather than as tampering, which is what somebody debugging needs to know.
     */
    public static function isValid(string $company, int $payslip, int $expires, string $signature, string $filename): bool
    {
        if (Carbon::createFromTimestamp($expires)->isPast()) {
            return false;
        }

        return hash_equals(self::signature($company, $payslip, $expires, $filename), $signature);
    }

    private static function path(string $company, int $payslip, int $expires, string $filename): string
    {
        $signature = self::signature($company, $payslip, $expires, $filename);

        return "whatsapp-media/{$company}/payslip/{$payslip}/{$expires}/{$signature}/{$filename}";
    }

    /**
     * Keyed on the application key, so a link cannot be forged without it, and
     * over every part the URL names — swap any one of them and the hash changes.
     */
    private static function signature(string $company, int $payslip, int $expires, string $filename): string
    {
        return hash_hmac(
            'sha256',
            implode('|', [$company, $payslip, $expires, $filename]),
            (string) config('app.key'),
        );
    }
}
