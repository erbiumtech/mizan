{{-- Dompdf renders this too: tables and inline styles only, no flexbox or grid —
     the same constraint as pdfs.personal-return-pack, and the same data-pack
     format: this is the accountant's working paper, not a letterhead letter. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Corporate return pack {{ $pack['year']->name }}</title>
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
        .fine { font-size: 9px; color: #6b7280; margin-top: 16px; }
    </style>
</head>
<body>
    <h1>Corporate tax return pack — {{ $pack['year']->name }}</h1>
    <p class="muted">
        FBR Tax Year {{ $pack['year']->end_date->format('Y') }} ·
        prepared {{ now()->format('d M Y') }} · figures from posted entries only ·
        for entry into IRIS — this document has not been filed
    </p>

    <h2>From accounting profit to tax due</h2>
    <table>
        <tbody>
            <tr><td>Revenue (turnover)</td><td class="num">{{ number_format($pack['pnl']['income']['total'], 2) }}</td></tr>
            <tr><td>Expenses</td><td class="num">({{ number_format($pack['pnl']['expenses']['total'], 2) }})</td></tr>
            <tr class="total"><td>Accounting profit per the ledger</td><td class="num">{{ number_format($pack['pnl']['net_profit'], 2) }}</td></tr>
            @foreach ($pack['worksheet']['adjustments'] as $adjustment)
                <tr>
                    <td class="muted">{{ $adjustment['label'] !== '' ? $adjustment['label'] : 'Adjustment' }}</td>
                    <td class="num muted">{{ $adjustment['amount'] < 0 ? '(' . number_format(abs($adjustment['amount']), 2) . ')' : number_format($adjustment['amount'], 2) }}</td>
                </tr>
            @endforeach
            @if ($pack['loss_applied'] > 0)
                <tr><td class="muted">Less: brought-forward loss set off</td><td class="num muted">({{ number_format($pack['loss_applied'], 2) }})</td></tr>
            @endif
            <tr class="total"><td>Taxable income</td><td class="num">{{ number_format($pack['taxable_income'], 2) }}</td></tr>
            <tr><td>Normal tax at {{ rtrim(rtrim(number_format($pack['worksheet']['tax_rate'], 2), '0'), '.') }}%</td><td class="num">{{ number_format($pack['normal_tax'], 2) }}</td></tr>
            <tr><td>Minimum tax at {{ rtrim(rtrim(number_format($pack['worksheet']['minimum_tax_rate'], 2), '0'), '.') }}% of turnover (s.113)</td><td class="num">{{ number_format($pack['minimum_tax'], 2) }}</td></tr>
            <tr><td>Tax on income — {{ $pack['basis'] === 'minimum' ? 'minimum applies' : 'normal applies' }}</td><td class="num">{{ number_format(max($pack['normal_tax'], $pack['minimum_tax']), 2) }}</td></tr>
            @if ($pack['super_tax'] > 0)
                <tr><td>Add: super tax (s.4C)</td><td class="num">{{ number_format($pack['super_tax'], 2) }}</td></tr>
            @endif
            <tr class="total"><td>Tax due</td><td class="num">{{ number_format($pack['tax_due'], 2) }}</td></tr>
            <tr><td>Less: advance income tax suffered (account 1260)</td><td class="num">({{ number_format($pack['tax_paid'], 2) }})</td></tr>
            <tr class="total"><td>{{ $pack['balance'] >= 0 ? 'Payable with the return' : 'Refundable / carry forward' }}</td><td class="num">{{ number_format(abs($pack['balance']), 2) }}</td></tr>
        </tbody>
    </table>

    <h2>The profit and loss behind it</h2>
    <table>
        <thead>
            <tr><th>Account</th><th class="num">Amount</th></tr>
        </thead>
        <tbody>
            @foreach (['income' => 'Income', 'expenses' => 'Expenses'] as $side => $label)
                <tr><td colspan="2" class="muted" style="text-transform: uppercase; font-size: 9px;">{{ $label }}</td></tr>
                @foreach ($pack['pnl'][$side]['rows'] as $row)
                    <tr>
                        <td>{{ $row['code'] }} — {{ $row['name'] }}</td>
                        <td class="num">{{ number_format($row['amount'], 2) }}</td>
                    </tr>
                @endforeach
                <tr class="total"><td>Total {{ strtolower($label) }}</td><td class="num">{{ number_format($pack['pnl'][$side]['total'], 2) }}</td></tr>
            @endforeach
        </tbody>
    </table>

    <p class="fine">
        Prepared from the company's own books; not tax advice and not a filed return. The adjustment rows are
        hand-kept — tax depreciation, inadmissible expenses and exempt income live in the Ordinance, not the
        ledger. Super tax (s.4C) is not computed here. Enter the figures at iris.fbr.gov.pk, or hand this
        document to the company's tax practitioner.
    </p>
</body>
</html>
