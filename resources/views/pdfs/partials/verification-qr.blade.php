{{--
    The verification code — docs/saas-launch-plan.md §7.5. Printed only when the company has switched
    verification on (the service hands a null otherwise), so a document never carries a link that
    answers 404. Plain table, no flexbox, for Dompdf.

    @param ?string $verification_qr  a data URI, from App\Support\DocumentVerification::qrFor()
--}}
@if (! empty($verification_qr))
    <table style="width: 100%; margin: 10pt 0 0; border-collapse: collapse;">
        <tr>
            <td style="width: 70pt; padding: 0; vertical-align: top;"><img src="{{ $verification_qr }}" alt="" style="width: 64pt; height: 64pt;"></td>
            <td style="padding: 0 0 0 8pt; vertical-align: top; font-size: 8pt; color: #555;">
                Scan to verify this document against the company's records. The page shows whether the facts
                printed here still match, and nothing else.
            </td>
        </tr>
    </table>
@endif
