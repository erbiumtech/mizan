{{-- Dompdf renders this too: tables and inline styles only. The accountant's
     working paper for a slab-taxed business (sole proprietor or AOP). --}}
@php($isAop = ($pack['entity'] ?? null) === \App\Modules\Core\Models\Company::LEGAL_AOP)
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $pack['entity_label'] ?? 'Sole Proprietor' }} return pack {{ $pack['year']->name }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2937; margin: 28px; }
        h1 { font-size: 16px; margin: 0 0 2px; }
        h2 { font-size: 12px; margin: 18px 0 6px; border-bottom: 1px solid #d1d5db; padding-bottom: 3px; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 4px 6px; border-bottom: 1px solid #f3f4f6; }
        .num { text-align: right; white-space: nowrap; }
        .total td { font-weight: bold; border-top: 1px solid #9ca3af; border-bottom: none; }
        .muted { color: #6b7280; }
        .fine { font-size: 9px; color: #6b7280; margin-top: 16px; }
    </style>
</head>
<body>
    <h1>{{ $pack['entity_label'] ?? 'Sole Proprietor' }} return pack — {{ $pack['year']->name }}</h1>
    <p class="muted">
        FBR Tax Year {{ $pack['year']->end_date->format('Y') }} · prepared {{ now()->format('d M Y') }} ·
        business profit on the {{ $isAop ? 'non-salaried/AOP' : 'individual' }} slabs · for entry into IRIS — not filed
    </p>

    <h2>From accounting profit to tax due</h2>
    <table>
        <tbody>
            <tr><td>Revenue</td><td class="num">{{ number_format($pack['pnl']['income']['total'], 2) }}</td></tr>
            <tr><td>Expenses</td><td class="num">({{ number_format($pack['pnl']['expenses']['total'], 2) }})</td></tr>
            <tr class="total"><td>Accounting profit per the ledger</td><td class="num">{{ number_format($pack['pnl']['net_profit'], 2) }}</td></tr>
            @foreach ($pack['worksheet']['adjustments'] as $adjustment)
                <tr>
                    <td class="muted">{{ $adjustment['label'] !== '' ? $adjustment['label'] : 'Adjustment' }}</td>
                    <td class="num muted">{{ $adjustment['amount'] < 0 ? '(' . number_format(abs($adjustment['amount']), 2) . ')' : number_format($adjustment['amount'], 2) }}</td>
                </tr>
            @endforeach
            <tr class="total"><td>Taxable income</td><td class="num">{{ number_format($pack['taxable_income'], 2) }}</td></tr>
            <tr><td>Tax on individual slabs{{ $pack['tax']['bracket'] ? ' — ' . $pack['tax']['bracket']->label() : '' }}{{ ($pack['tax']['surcharge'] ?? 0) > 0 ? ' (incl. surcharge)' : '' }}</td><td class="num">{{ number_format($pack['slab_tax'], 2) }}</td></tr>
            <tr><td>Minimum tax at {{ rtrim(rtrim(number_format($pack['worksheet']['minimum_tax_rate'], 2), '0'), '.') }}% of turnover (s.113){{ $pack['minimum_tax'] == 0 && $pack['turnover'] < $pack['worksheet']['minimum_tax_threshold'] ? ' — below threshold' : '' }}</td><td class="num">{{ number_format($pack['minimum_tax'], 2) }}</td></tr>
            <tr class="total"><td>Tax due — {{ $pack['basis'] === 'minimum' ? 'minimum applies' : 'slab applies' }}</td><td class="num">{{ number_format($pack['tax_due'], 2) }}</td></tr>
            <tr><td>Less: advance income tax suffered (account 1260)</td><td class="num">({{ number_format($pack['tax_paid'], 2) }})</td></tr>
            <tr class="total"><td>{{ $pack['balance'] >= 0 ? 'Payable with the return' : 'Refundable' }}</td><td class="num">{{ number_format(abs($pack['balance']), 2) }}</td></tr>
        </tbody>
    </table>

    <p class="fine">
        @if ($isAop)
            An AOP/partnership files its own return; its profit is taxed on the non-salaried/AOP slab schedule.
            Each partner's share is then excluded in the partner's own hands — it has already borne tax at the
            AOP, so it is not taxed twice.
        @else
            A sole proprietor files an individual return (114(1)); this is the business-income head, taxed on the
            owner's individual slabs. Salary, rental or other personal income is entered alongside it on the return.
        @endif
        Minimum tax (s.113) applies the greater of the slab tax and the turnover rate, but only above the
        threshold on the worksheet — confirm that figure, as it moves between Finance Acts. The
        adjustments are hand-kept; advance tax is the movement of account 1260. Not tax advice, not a filed
        return — enter the figures at iris.fbr.gov.pk or hand this to your practitioner.
    </p>
</body>
</html>
