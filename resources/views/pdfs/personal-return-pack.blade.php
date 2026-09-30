{{-- Dompdf renders this too, so: tables and inline styles only, no flexbox or grid.
     Shaped section by section on a real IRIS 114(1) print, codes included, so
     transcription is code-by-code rather than a hunt. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Return pack {{ $pack['year']->name }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2937; margin: 28px; }
        h1 { font-size: 16px; margin: 0 0 2px; }
        h2 { font-size: 12px; margin: 18px 0 6px; border-bottom: 1px solid #d1d5db; padding-bottom: 3px; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; font-size: 9px; text-transform: uppercase; color: #6b7280; padding: 4px 6px; border-bottom: 1px solid #d1d5db; }
        td { padding: 4px 6px; border-bottom: 1px solid #f3f4f6; }
        .num { text-align: right; white-space: nowrap; }
        .code { color: #6b7280; white-space: nowrap; }
        .total td { font-weight: bold; border-top: 1px solid #9ca3af; border-bottom: none; }
        .muted { color: #6b7280; }
        .warn { color: #b45309; }
        .indent { padding-left: 18px; color: #6b7280; }
        .fine { font-size: 9px; color: #6b7280; margin-top: 16px; }
    </style>
</head>
<body>
    <h1>114(1) return pack — {{ $pack['year']->name }}</h1>
    <p class="muted">
        FBR Tax Year {{ $pack['year']->end_date->format('Y') }} ·
        period {{ $pack['year']->start_date->format('d-M-Y') }} – {{ $pack['year']->end_date->format('d-M-Y') }} ·
        prepared {{ now()->format('d M Y') }} · figures from posted entries only ·
        for entry into IRIS — this document has not been filed
    </p>

    <h2>Income by head</h2>
    <table>
        <thead>
            <tr>
                <th>Description</th>
                <th>Code</th>
                <th class="num">Total income</th>
                <th class="num">Subject to final tax</th>
                <th class="num">Subject to exemption</th>
                <th class="num">Subject to normal tax</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($pack['heads'] as $head)
                <tr>
                    <td>{{ $head['label'] }}</td>
                    <td class="code">{{ $head['code'] }}</td>
                    <td class="num">{{ number_format($head['total'], 2) }}</td>
                    <td class="num">{{ number_format($head['final'], 2) }}</td>
                    <td class="num muted">{{ number_format($head['exempt'], 2) }}</td>
                    <td class="num">{{ number_format($head['normal'], 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    @if ($pack['income']['unclassified'] > 0)
        <p class="warn">
            PKR {{ number_format($pack['income']['unclassified'], 2) }} of income has no “Taxed as” setting and
            is not assessed above; it is included in the reconciliation. Classify it before filing.
        </p>
    @endif

    <h2>Computations</h2>
    <table>
        <tbody>
            @foreach ($pack['computations'] as $row)
                <tr @if (in_array($row['code'], ['9200', '9203'], true)) class="total" @endif>
                    <td>{{ $row['label'] }}</td>
                    <td class="code">{{ $row['code'] }}</td>
                    <td class="num">{{ number_format($row['amount'], 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if ($pack['withholding_by_section'] !== [])
        <h2>Withholding by section (9201 breakdown)</h2>
        <table>
            <thead>
                <tr><th>Section</th><th>Account</th><th class="num">Tax withheld</th></tr>
            </thead>
            <tbody>
                @foreach ($pack['withholding_by_section'] as $row)
                    <tr>
                        <td>{{ $row['section'] }}</td>
                        <td class="code">{{ $row['code'] }}</td>
                        <td class="num">{{ number_format($row['tax'], 2) }}</td>
                    </tr>
                @endforeach
                <tr class="total"><td>Total (9201)</td><td></td><td class="num">{{ number_format($pack['tax_paid'], 2) }}</td></tr>
            </tbody>
        </table>
    @endif

    <h2>Wealth statement</h2>
    <table>
        <thead>
            <tr>
                <th>Account</th>
                <th>IRIS code</th>
                <th class="num">Opening</th>
                <th class="num">Closing</th>
            </tr>
        </thead>
        <tbody>
            @foreach (['assets' => 'Assets', 'liabilities' => 'Liabilities'] as $side => $label)
                @if ($pack['wealth'][$side] !== [])
                    <tr><td colspan="4" class="muted" style="text-transform: uppercase; font-size: 9px;">{{ $label }}</td></tr>
                    @foreach ($pack['wealth'][$side] as $row)
                        <tr>
                            <td>{{ $row['code'] }} — {{ $row['name'] }}</td>
                            <td class="code">{{ $row['iris_code'] }} {{ $row['iris_label'] }}</td>
                            <td class="num">{{ number_format($row['opening'], 2) }}</td>
                            <td class="num">{{ number_format($row['closing'], 2) }}</td>
                        </tr>
                    @endforeach
                @endif
            @endforeach
            <tr class="total">
                <td>Net assets</td>
                <td class="code">703001</td>
                <td class="num">{{ number_format($pack['wealth']['opening_net'], 2) }}</td>
                <td class="num">{{ number_format($pack['wealth']['closing_net'], 2) }}</td>
            </tr>
        </tbody>
    </table>

    <h2>Reconciliation of net assets</h2>
    <table>
        <tbody>
            @foreach ($pack['reconciliation'] as $row)
                <tr @if ($row['code'] === '703000') class="total" @endif>
                    <td @if (in_array($row['code'], ['7031', '7033', '7089'], true)) class="indent" @endif>{{ $row['label'] }}</td>
                    <td class="code">{{ $row['code'] }}</td>
                    <td class="num @if ($row['code'] === '703000' && abs($row['amount']) >= 0.005) warn @endif">{{ number_format($row['amount'], 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <p class="fine">
        Prepared from this account's own books; not tax advice and not a filed return. Withholding (9201) is
        the movement of the tax-withheld accounts (1600, and 1601–1604 by section) — post to a section account
        for the per-section breakdown above; anything general stays on 1600.
        Credits, receipted deductions, holding-period capital gains rates and exempt income are
        outside what the ledger can know. Enter the figures at iris.fbr.gov.pk against the codes shown, or
        hand this document to your tax practitioner.
    </p>
</body>
</html>
