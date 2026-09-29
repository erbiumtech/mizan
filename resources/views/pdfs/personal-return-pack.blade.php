{{-- Dompdf renders this too, so: tables and inline styles only, no flexbox or grid.
     Same constraint every pdfs.* template lives under — see the payslip template. --}}
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
        .total td { font-weight: bold; border-top: 1px solid #9ca3af; border-bottom: none; }
        .muted { color: #6b7280; }
        .warn { color: #b45309; }
        .fine { font-size: 9px; color: #6b7280; margin-top: 16px; }
    </style>
</head>
<body>
    <h1>Personal tax return pack — {{ $pack['year']->name }}</h1>
    <p class="muted">
        FBR Tax Year {{ $pack['year']->end_date->format('Y') }} ·
        prepared {{ now()->format('d M Y') }} · figures from posted entries only ·
        for entry into IRIS — this document has not been filed
    </p>

    <h2>Income and tax — the 114(1) figures</h2>
    <table>
        <thead>
            <tr>
                <th>Head of income</th>
                <th class="num">Gross</th>
                <th class="num">Allowance</th>
                <th class="num">Taxable</th>
                <th class="num">Tax</th>
                <th class="num">Surcharge</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($pack['income']['regimes'] as $row)
                <tr>
                    <td>{{ $row['label'] }}</td>
                    <td class="num">{{ number_format($row['income'], 2) }}</td>
                    <td class="num muted">{{ ($row['allowance'] ?? 0) > 0 ? '(' . number_format($row['allowance'], 2) . ')' : '—' }}</td>
                    <td class="num">{{ number_format($row['taxable'], 2) }}</td>
                    <td class="num">{{ number_format($row['tax'], 2) }}</td>
                    <td class="num">{{ ($row['surcharge'] ?? 0) > 0 ? number_format($row['surcharge'], 2) : '—' }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td>Tax chargeable</td>
                <td class="num">{{ number_format($pack['income']['total_income'], 2) }}</td>
                <td></td><td></td>
                <td class="num" colspan="2">{{ number_format($pack['income']['total_payable'], 2) }}</td>
            </tr>
            <tr>
                <td>Tax already paid or withheld (account 1600)</td>
                <td></td><td></td><td></td>
                <td class="num" colspan="2">({{ number_format($pack['tax_paid'], 2) }})</td>
            </tr>
            <tr class="total">
                <td>{{ $pack['balance'] >= 0 ? 'Payable with the return' : 'Refundable' }}</td>
                <td></td><td></td><td></td>
                <td class="num" colspan="2">{{ number_format(abs($pack['balance']), 2) }}</td>
            </tr>
        </tbody>
    </table>
    @if ($pack['income']['unclassified'] > 0)
        <p class="warn">
            PKR {{ number_format($pack['income']['unclassified'], 2) }} of income has no “Taxed as” setting and is
            not assessed above. It is included in the reconciliation below. Classify it before filing.
        </p>
    @endif

    <h2>Wealth statement — assets and liabilities</h2>
    <table>
        <thead>
            <tr>
                <th>Account</th>
                <th class="num">Opening</th>
                <th class="num">Closing</th>
            </tr>
        </thead>
        <tbody>
            @foreach (['assets' => 'Assets', 'liabilities' => 'Liabilities'] as $side => $label)
                @if ($pack['wealth'][$side] !== [])
                    <tr><td colspan="3" class="muted" style="text-transform: uppercase; font-size: 9px;">{{ $label }}</td></tr>
                    @foreach ($pack['wealth'][$side] as $row)
                        <tr>
                            <td>{{ $row['code'] }} — {{ $row['name'] }}</td>
                            <td class="num">{{ number_format($row['opening'], 2) }}</td>
                            <td class="num">{{ number_format($row['closing'], 2) }}</td>
                        </tr>
                    @endforeach
                @endif
            @endforeach
            <tr class="total">
                <td>Net assets</td>
                <td class="num">{{ number_format($pack['wealth']['opening_net'], 2) }}</td>
                <td class="num">{{ number_format($pack['wealth']['closing_net'], 2) }}</td>
            </tr>
        </tbody>
    </table>

    <h2>Reconciliation of net assets</h2>
    @php($rec = $pack['reconciliation'])
    <table>
        <tbody>
            <tr><td>Net assets at the start of the year</td><td class="num">{{ number_format($rec['opening_net'], 2) }}</td></tr>
            <tr><td>Add: income recorded this year</td><td class="num">{{ number_format($rec['inflows'], 2) }}</td></tr>
            <tr><td>Less: personal expenses this year</td><td class="num">({{ number_format($rec['expenses'], 2) }})</td></tr>
            <tr><td>Net assets that should result</td><td class="num">{{ number_format($rec['expected_closing'], 2) }}</td></tr>
            <tr><td>Net assets the books actually show</td><td class="num">{{ number_format($rec['actual_closing'], 2) }}</td></tr>
            <tr class="total">
                <td>Unexplained difference</td>
                <td class="num {{ abs($rec['unexplained']) >= 0.005 ? 'warn' : '' }}">{{ number_format($rec['unexplained'], 2) }}</td>
            </tr>
        </tbody>
    </table>

    <p class="fine">
        Prepared from this account's own books; not tax advice and not a filed return. Credits, receipted
        deductions and holding-period capital gains rates are outside what the ledger can know. Enter the
        figures at iris.fbr.gov.pk, or hand this document to your tax practitioner.
    </p>
</body>
</html>
