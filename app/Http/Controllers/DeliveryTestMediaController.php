<?php

namespace App\Http\Controllers;

use App\Support\DeliveryTestLink;
use App\Support\Pdf\Pdf;
use Illuminate\Http\Response;

/**
 * Serves the document `whatsapp:test` asks a provider to fetch.
 *
 * There is no session and no signed-in user here — the reader is Twilio — so the signature in the
 * path is the whole of the authorization, and it is checked before anything is rendered. The
 * document is generated on the spot and holds nothing but the application's name and the time.
 */
class DeliveryTestMediaController extends Controller
{
    public function __invoke(int $expires, string $signature, string $filename): Response
    {
        abort_unless(DeliveryTestLink::isValid($expires, $signature, $filename), 403, 'This test link is not valid or has expired.');

        return response(
            Pdf::view('pdfs.delivery-test', [
                'application' => config('app.name'),
                'sentAt' => now()->toDayDateTimeString(),
            ])->raw(),
            200,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="'.basename($filename).'"',
                'Cache-Control' => 'no-store, private',
            ],
        );
    }
}
