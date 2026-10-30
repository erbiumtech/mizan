{{--
    A monthly progress report, alone or beside the one before it.

    Everything it needs is in this file: no stylesheet, script or image is fetched, so it renders the
    same under headless Chrome and Dompdf and on a server with no way out. It used to take its styling
    from the Tailwind browser CDN — a <script>, which Dompdf never runs and Chrome waits up to the PDF
    timeout for when the network is closed — and printed one particular company's logo, name and
    address on every tenant's report. The company is now this company: its letterhead name, address,
    numbers and uploaded logo, in its own brand colour and typeface (Company Settings).

    Layout is tables and blocks only, because Dompdf has no flexbox or grid. Engine-specific
    corrections live in partials/dompdf-mpr-report, the pattern every PDF here follows.
--}}
@php
    $company = \App\Support\CompanyLetterhead::data();
    $companyName = \App\Support\CompanyLetterhead::displayName();
    $logo = \App\Support\CompanyLetterhead::logoDataUri();
    $brand = \App\Support\BrandKit::forTemplate();
    $address = trim(preg_replace('/\s*\R\s*/', ', ', (string) ($company['address'] ?? '')));
    $numbers = array_filter([
        ($company['registration_no'] ?? '') !== '' ? 'Reg. No. '.$company['registration_no'] : null,
        ($company['ntn'] ?? '') !== '' ? 'NTN '.$company['ntn'] : null,
    ]);
@endphp
<!DOCTYPE html>
<html lang="{{ $documentLocale ?? 'en' }}" @if ($documentRtl ?? false) dir="rtl" @endif>
<head>
    <meta charset="UTF-8">
    <title>Monthly Progress Report — {{ $companyName }}</title>
    <style>
        @page {
            size: a4 {{ $mode === 'comparison' ? 'landscape' : 'portrait' }};
            margin: 0 15mm;
        }
        body {
            margin: 0;
            padding: 0;
            background: #ffffff;
            color: #0f172a;
            font-family: {!! $brand['font'] !!};
            font-size: 14px;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        p, h2, h3 { margin: 0; }

        /* The page scaffold: thead and tfoot spacers repeat on every page and keep the flowing
           content clear of the top edge and of the fixed footer. */
        .page { width: 100%; border-collapse: collapse; }
        .page td { padding: 0; border: none; }
        .header-space { height: 10mm; }
        .footer-space { height: 24mm; }

        .avoid-break { page-break-inside: avoid; break-inside: avoid-page; }

        /* Masthead: who the report is for on the left, the company's logo on the right. */
        .masthead { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
        .masthead td { padding: 0; vertical-align: bottom; }
        .masthead .logo-cell { text-align: right; }
        .masthead img { height: 44px; }
        .eyebrow { font-size: 11px; font-weight: bold; letter-spacing: 0.05em; text-transform: uppercase; color: {{ $brand['colour'] }}; }
        .title { font-size: 20px; font-weight: bold; color: #0f172a; margin-top: 2px; }

        /* Name and date. */
        .facts { width: 100%; border-collapse: collapse; }
        .facts td { border: 1px solid #475569; padding: 10px 14px; font-size: 14px; }
        .facts .label { width: 30%; font-weight: bold; color: #1e293b; background: #f8fafc; }

        /* One numbered answer. Never a percentage width on these: Chrome and Dompdf disagree about
           whether padding is inside it, and a block left at auto fills its column in both. */
        .section { margin-top: 16px; }
        .section h3 { font-size: 14px; font-weight: bold; color: #0f172a; margin-bottom: 6px; }
        .answer {
            border: 1px solid #475569;
            padding: 8px 14px;
            line-height: 1.6;
            white-space: pre-line;
            color: #1e293b;
            font-size: 14px;
        }

        /* Two columns, previous beside latest — a fixed table rather than a grid. The gutter is a
           margin on a block inside each cell, not padding on the cell: a padded 50% cell is wider than
           half the page in Chrome, and the right-hand column ran off the paper. */
        .pair { width: 100%; border-collapse: separate; border-spacing: 0; table-layout: fixed; }
        .pair td.col { width: 50%; vertical-align: top; padding: 0; border: none; }
        .gap-right { margin-right: 4mm; }
        /* The hair on the right: Chrome splits the columns at a fractional pixel, and a border that
           lands exactly on the page edge prints at half its width. */
        .gap-left { margin-left: 4mm; margin-right: 0.3mm; }
        .badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 4px;
            background: #334155;
            color: #ffffff;
            font-size: 12px;
            font-weight: bold;
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }
        /* Darker, not heavier: Dompdf has no 600 weight and swaps the whole column to another face. */
        .latest .answer, .latest .facts td { color: #0f172a; }

        .thanks { margin-top: 18px; font-weight: bold; color: {{ $brand['colour'] }}; }

        /* The company's own foot, repeated on every page. */
        .footer {
            position: fixed;
            bottom: 6mm;
            left: 0;
            width: 100%;
            font-size: 10px;
            color: #94a3b8;
        }
        .footer .name { font-size: 11px; font-weight: bold; color: {{ $brand['colour'] }}; }
        .footer .numbers { font-size: 9px; color: #64748b; margin-top: 2px; }
    </style>

    @if (($pdfEngine ?? null) === 'dompdf')
        @include('pdfs.partials.dompdf-mpr-report')
    @endif
</head>
<body>
    <table class="page">
        <thead class="page-head"><tr><td><div class="header-space"></div></td></tr></thead>
        <tbody class="page-body">
            <tr class="page-row">
                <td class="page-cell">
                    <table class="masthead avoid-break">
                        <tr>
                            <td>
                                <div class="eyebrow">{{ $companyName }}</div>
                                <h2 class="title">Monthly Progress Report</h2>
                            </td>
                            @if ($logo)
                                <td class="logo-cell"><img src="{{ $logo }}" alt=""></td>
                            @endif
                        </tr>
                    </table>

                    @if ($mode === 'single')
                        <table class="facts avoid-break">
                            <tr><td class="label">Name</td><td>{{ $reportFields['User Name'] ?? '---' }}</td></tr>
                            <tr><td class="label">Date</td><td>{{ $reportFields['MPR Date'] ?? '---' }}</td></tr>
                        </table>

                        @foreach ($contentLabels as $id => $label)
                            <div class="section avoid-break">
                                <h3>{{ $id }}. {{ $label }}</h3>
                                <div class="answer">{{ $reportFields[$label] ?? '---' }}</div>
                            </div>
                        @endforeach
                    @endif

                    @if ($mode === 'comparison')
                        <table class="pair avoid-break">
                            <tr>
                                <td class="col"><div class="gap-right"><span class="badge">Previous — {{ $previous['MPR Date'] ?? '---' }}</span></div></td>
                                <td class="col"><div class="gap-left"><span class="badge">Latest — {{ $latest['MPR Date'] ?? '---' }}</span></div></td>
                            </tr>
                        </table>

                        <table class="pair avoid-break" style="margin-top: 12px;">
                            <tr>
                                <td class="col">
                                    <div class="gap-right">
                                        <table class="facts">
                                            <tr><td class="label">Name</td><td>{{ $previous['User Name'] ?? '---' }}</td></tr>
                                            <tr><td class="label">Date</td><td>{{ $previous['MPR Date'] ?? '---' }}</td></tr>
                                        </table>
                                    </div>
                                </td>
                                <td class="col latest">
                                    <div class="gap-left">
                                        <table class="facts">
                                            <tr><td class="label">Name</td><td>{{ $latest['User Name'] ?? '---' }}</td></tr>
                                            <tr><td class="label">Date</td><td>{{ $latest['MPR Date'] ?? '---' }}</td></tr>
                                        </table>
                                    </div>
                                </td>
                            </tr>
                        </table>

                        @foreach ($contentLabels as $id => $label)
                            <div class="section avoid-break">
                                <h3>{{ $id }}. {{ $label }}</h3>
                                <table class="pair">
                                    <tr>
                                        <td class="col"><div class="gap-right answer">{{ $previous[$label] ?? '---' }}</div></td>
                                        <td class="col latest"><div class="gap-left answer">{{ $latest[$label] ?? '---' }}</div></td>
                                    </tr>
                                </table>
                            </div>
                        @endforeach
                    @endif

                    <p class="thanks avoid-break">Thank you</p>
                </td>
            </tr>
        </tbody>
        <tfoot class="page-foot"><tr><td><div class="footer-space"></div></td></tr></tfoot>
    </table>

    <div class="footer">
        <p class="name">{{ $companyName }}</p>
        @if ($address !== '')
            <p>{{ $address }}</p>
        @endif
        @if ($numbers)
            <p class="numbers">{{ implode('  ·  ', $numbers) }}</p>
        @endif
    </div>
</body>
</html>
