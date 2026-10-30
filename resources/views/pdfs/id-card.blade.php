{{--
    A staff ID card — docs/saas-launch-plan.md §14.10.

    One card per page at CR80 (85.6 × 54mm), or several on an A4 sheet when `$sheet` is
    a list. Absolutely positioned in millimetres, like the cheque: that renders the same
    under Chromium and Dompdf, so there is no per-engine partial to keep in step.

    @param array<int, array<string, mixed>> $cards  each: card, employee, photo, qr
    @param array<string, string> $brand            from App\Support\BrandKit::forTemplate()
    @param array<string, mixed>  $company          from App\Support\CompanyLetterhead::data()
    @param bool $sheet                             several to an A4 page, for printing
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $sheet ? 'ID cards' : 'ID card' }}</title>
    <style>
        @page { margin: {{ $sheet ? '10mm' : '0' }}; size: {{ $sheet ? 'A4 portrait' : '85.6mm 54mm' }}; }
        html, body { margin: 0; padding: 0; }
        body { font-family: {{ $brand['font'] }}; color: #111; }

        .card {
            position: relative;
            width: 85.6mm; height: 54mm;
            overflow: hidden;
            box-sizing: border-box;
            {{ $sheet ? 'border: 0.2mm dashed #9ca3af; margin: 0 4mm 4mm 0; display: inline-block; vertical-align: top;' : '' }}
        }

        /* The colour band carries the company name, so a card is recognisable across a room. */
        .band { position: absolute; left: 0; top: 0; width: 85.6mm; height: 11mm; background: {{ $brand['colour'] }}; }
        .band .name { position: absolute; left: 4mm; top: 3mm; color: #fff; font-size: 9pt; font-weight: bold; letter-spacing: .02em; }
        .band .logo { position: absolute; right: 3mm; top: 1.5mm; height: 8mm; }

        .photo { position: absolute; left: 4mm; top: 15mm; width: 20mm; height: 25mm; object-fit: cover; border: 0.3mm solid #d1d5db; }
        .nophoto { position: absolute; left: 4mm; top: 15mm; width: 20mm; height: 25mm; border: 0.3mm dashed #d1d5db; }

        .who { position: absolute; left: 27mm; top: 15mm; width: 40mm; }
        .who .n { font-size: 11pt; font-weight: bold; line-height: 1.15; color: {{ $brand['accent'] }}; }
        .who .d { font-size: 8pt; margin-top: 1mm; color: #374151; }
        .who .c { font-size: 7.5pt; margin-top: 2mm; color: #6b7280; }

        .qr { position: absolute; right: 4mm; top: 16mm; width: 17mm; height: 17mm; }

        .foot { position: absolute; left: 4mm; right: 4mm; bottom: 3mm; font-size: 6.5pt; color: #6b7280; }
        .foot .r { float: right; }
        .void { position: absolute; left: 0; top: 22mm; width: 85.6mm; text-align: center; font-size: 16pt; font-weight: bold; color: #dc2626; letter-spacing: .1em; }
    </style>
</head>
<body>
@foreach ($cards as $entry)
    @php($card = $entry['card'])
    @php($employee = $entry['employee'])
    <div class="card">
        <div class="band">
            <span class="name">{{ strtoupper($company['legal_name'] ?: config('app.name')) }}</span>
            @if ($entry['logo'] ?? null)
                <img src="{{ $entry['logo'] }}" class="logo" alt="">
            @endif
        </div>

        @if ($entry['photo'] ?? null)
            <img src="{{ $entry['photo'] }}" class="photo" alt="">
        @else
            <div class="nophoto"></div>
        @endif

        <div class="who">
            <div class="n">{{ $employee->fullName() }}</div>
            <div class="d">{{ $employee->designation ?: '' }}</div>
            <div class="c">{{ $employee->employee_id }}</div>
        </div>

        {{-- The QR of DocumentVerification, which answers against the live record. Absent
             where verification is switched off, and the card is still a card. --}}
        @if ($entry['qr'] ?? null)
            <img src="{{ $entry['qr'] }}" class="qr" alt="">
        @endif

        @if ($card->isRevoked())
            <div class="void">CANCELLED</div>
        @endif

        <div class="foot">
            No. {{ $card->serial }}
            <span class="r">{{ $card->expires_on ? 'Valid to '.\App\Support\CompanyDates::write($card->expires_on) : 'No expiry' }}</span>
        </div>
    </div>
@endforeach
</body>
</html>
