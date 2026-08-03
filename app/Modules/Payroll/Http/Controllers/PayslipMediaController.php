<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Company;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Services\PayslipService;
use App\Modules\Payroll\Support\PayslipMediaLink;
use Illuminate\Http\Request;

/**
 * The payslip PDF, for Twilio to fetch.
 *
 * Twilio sends WhatsApp media by link: it is handed a `MediaUrl` and collects the
 * file itself, so unlike every other route in this app the reader is not a person
 * with a session. That makes this the one unauthenticated path to somebody's
 * salary, and it is bounded three ways:
 *
 *  - **Signed.** The URL carries an HMAC over the whole of it, so the payslip id
 *    cannot be edited to fetch somebody else's. A tampered link is rejected by
 *    the `signed` middleware before this method runs.
 *  - **Expiring.** Minutes, not days (whatsapp.media_url_ttl). Twilio fetches
 *    immediately; after that the link is dead, so a URL later found in a log is
 *    worth nothing.
 *  - **Rendered, never stored.** The payslip is drawn from the record as it
 *    stands, so there is no file left behind to leak afterwards.
 *
 * The content-type is set explicitly because Twilio checks it against the file
 * and refuses the message if the two disagree — see the guidance this driver
 * follows in TwilioWhatsAppSender.
 */
class PayslipMediaController extends Controller
{
    public function __invoke(
        Request $request,
        string $company,
        int $payslip,
        int $expires,
        string $signature,
        string $filename,
    ) {
        // Checked first, before a single row is read: the signature is what stands
        // in for a session here.
        abort_unless(
            PayslipMediaLink::isValid($company, $payslip, $expires, $signature, $filename),
            403,
            'This payslip link is not valid or has expired.',
        );

        // The company is resolved and made current here rather than by the
        // `company:` middleware, which asks whether the *signed-in user* may reach
        // it — there is no signed-in user on this route. The signature is the
        // authorization, and it covers the company segment too.
        $tenant = Company::where('slug', $company)->first();

        abort_unless($tenant, 404);

        $tenant->activate();

        try {
            $record = Payslip::with('employee.user', 'fiscalYear')->find($payslip);

            abort_unless($record, 404);

            // No "has it been released yet" check here, and that is not an
            // oversight. Twilio fetches this while the send is still in flight —
            // before sent_at is stamped, which only happens once something has
            // actually arrived — so such a guard would 404 on every real send and
            // pass only on a resend. The gate is the signature: this URL exists
            // because payroll chose to send this payslip, minutes ago.

            return response(
                app(PayslipService::class)->renderPdf($record)->raw(),
                200,
                [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'inline; filename="'.basename($filename).'"',
                    // Nothing in between should hold a copy of a payslip.
                    'Cache-Control' => 'no-store, private',
                ],
            );
        } finally {
            Company::forgetCurrent();
        }
    }
}
