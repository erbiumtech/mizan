<?php

namespace App\Support;

use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode as Renderer;
use chillerlan\QRCode\QROptions;
use Throwable;

/**
 * A QR code as a data URI, for the PDF engines that fetch no URLs.
 *
 * The same rendering `Invoice::fbrQrDataUri()` does for the FBR code, lifted
 * out for the document-verification codes (docs/saas-launch-plan.md §7.5).
 */
final class QrCode
{
    public static function dataUri(string $text, int $scale = 3): ?string
    {
        try {
            return (new Renderer(new QROptions([
                'outputType' => QROutputInterface::GDIMAGE_PNG,
                'scale' => $scale,
            ])))->render($text);
        } catch (Throwable) {
            // No GD extension degrades to no code: the template prints nothing
            // where the code would be. Same posture as the FBR code.
            return null;
        }
    }
}
