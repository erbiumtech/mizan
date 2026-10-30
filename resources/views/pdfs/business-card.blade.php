{{--
    Business cards — docs/saas-launch-plan.md §14.10.

    90 × 55mm, the size a Pakistani printer cuts to. Absolutely positioned in
    millimetres, like the cheque and the ID card, so Chromium and Dompdf agree.

    One card per page by default — a printer wants a single artwork and does its own
    imposition — or ten to an A4 sheet when `$sheet`, for an office that prints its own.

    @param array<int, array<string, mixed>> $cards
    @param array<string, string> $brand
    @param array<string, mixed> $company
    @param bool $sheet
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Business card</title>
    <style>
        @page { margin: {{ $sheet ? '10mm' : '0' }}; size: {{ $sheet ? 'A4 portrait' : '90mm 55mm' }}; }
        html, body { margin: 0; padding: 0; }
        body { font-family: {{ $brand['font'] }}; color: #111; }

        .card {
            position: relative;
            width: 90mm; height: 55mm;
            overflow: hidden;
            box-sizing: border-box;
            {{ $sheet ? 'border: 0.2mm dashed #9ca3af; margin: 0 4mm 4mm 0; display: inline-block; vertical-align: top;' : '' }}
        }

        /* A bar down the left rather than a block behind everything: a card is read at
           arm's length, and ink behind text is the first thing a cheap printer loses. */
        .bar { position: absolute; left: 0; top: 0; width: 4mm; height: 55mm; background: {{ $brand['colour'] }}; }

        .name { position: absolute; left: 10mm; top: 12mm; font-size: 13pt; font-weight: bold; color: {{ $brand['accent'] }}; }
        .title { position: absolute; left: 10mm; top: 18mm; font-size: 9pt; color: #4b5563; }
        .company { position: absolute; left: 10mm; top: 27mm; font-size: 11pt; font-weight: bold; color: {{ $brand['colour'] }}; }
        .lines { position: absolute; left: 10mm; top: 33mm; font-size: 7.5pt; color: #4b5563; line-height: 1.5; }
        .logo { position: absolute; right: 8mm; top: 9mm; height: 11mm; }
    </style>
</head>
<body>
@foreach ($cards as $entry)
    @php($order = $entry['order'])
    @php($employee = $entry['employee'])
    <div class="card">
        <div class="bar"></div>

        @if ($entry['logo'] ?? null)
            <img src="{{ $entry['logo'] }}" class="logo" alt="">
        @endif

        <div class="name">{{ $employee->fullName() }}</div>
        @if ($order->title() !== '')
            <div class="title">{{ $order->title() }}</div>
        @endif

        <div class="company">{{ $company['legal_name'] ?: config('app.name') }}</div>

        <div class="lines">
            @if ($company['address'])<div>{{ $company['address'] }}</div>@endif
            <div>
                @if ($order->phone() !== ''){{ $order->phone() }}@endif
                @if ($employee->user?->email) &nbsp;·&nbsp; {{ $employee->user->email }}@endif
            </div>
            @if ($company['website'])<div>{{ $company['website'] }}</div>@endif
        </div>
    </div>
@endforeach
</body>
</html>
