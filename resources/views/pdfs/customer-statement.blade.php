{{--
    Statement of account for one customer — docs/erpnext-gap-plan.md §4 item 1.

    Written for both engines without an override partial, like the two letters: a table of movements and a
    table of ageing, no flexbox, no grid. Everything is resolved in
    App\Modules\Invoicing\Services\CustomerStatement and arrives as figures; the template adds up nothing.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Statement of Account — {{ $contact->name }}</title>
    <style>
        @page { margin: 0; }
        table.lines { width: 100%; border-collapse: collapse; margin-top: 10mm; font-size: 10.5pt; }
        table.lines th, table.lines td { padding: 2.2mm 2.5mm; border-bottom: 1px solid #d9d9d9; text-align: left; vertical-align: top; }
        table.lines th { background: #f3f3f3; font-weight: 600; }
        table.lines td.num, table.lines th.num { text-align: right; white-space: nowrap; }
        table.lines tr.balance td { font-weight: 600; background: #fafafa; }
        table.ageing { width: 100%; border-collapse: collapse; margin-top: 8mm; font-size: 10.5pt; }
        table.ageing th, table.ageing td { padding: 2mm 2.5mm; border: 1px solid #d9d9d9; text-align: right; }
        table.ageing th { background: #f3f3f3; font-weight: 600; text-align: center; }
        .muted { color: #666; }
        p.closing { margin-top: 8mm; font-size: 11pt; }
    </style>

    @include('pdfs.partials.letterhead-styles')

    @if (($pdfEngine ?? null) === 'dompdf')
        @include('pdfs.partials.dompdf-letter')
    @endif
</head>
<body>
<div class="sheet">

@include('pdfs.partials.letterhead', ['company' => $company, 'title' => 'Statement of Account'])

<table class="meta">
    <tr>
        <td>
            <strong>{{ $contact->name }}</strong><br>
            @if ($contact->address_line_1){{ $contact->address_line_1 }}<br>@endif
            @if ($contact->address_line_2){{ $contact->address_line_2 }}<br>@endif
            @if ($contact->ntn)<span class="muted">NTN {{ $contact->ntn }}</span>@endif
        </td>
        <td class="right">
            Statement date: {{ $issued_on->format('d F Y') }}<br>
            Period: {{ \Illuminate\Support\Carbon::parse($from)->format('d M Y') }} to {{ \Illuminate\Support\Carbon::parse($to)->format('d M Y') }}
        </td>
    </tr>
</table>

<table class="lines">
    <thead>
        <tr>
            <th>Date</th>
            <th>Document</th>
            <th>Reference</th>
            <th>Detail</th>
            <th class="num">Debit</th>
            <th class="num">Credit</th>
            <th class="num">Balance</th>
        </tr>
    </thead>
    <tbody>
        <tr class="balance">
            <td>{{ \Illuminate\Support\Carbon::parse($from)->format('d M Y') }}</td>
            <td colspan="5">Opening balance</td>
            <td class="num">{{ number_format($opening, 2) }}</td>
        </tr>
        @forelse ($lines as $line)
            <tr>
                <td>{{ \Illuminate\Support\Carbon::parse($line['date'])->format('d M Y') }}</td>
                <td>{{ $line['type'] }}</td>
                <td>{{ $line['reference'] }}</td>
                <td class="muted">{{ $line['detail'] }}</td>
                <td class="num">{{ $line['debit'] > 0 ? number_format($line['debit'], 2) : '' }}</td>
                <td class="num">{{ $line['credit'] > 0 ? number_format($line['credit'], 2) : '' }}</td>
                <td class="num">{{ number_format($line['balance'], 2) }}</td>
            </tr>
        @empty
            <tr><td colspan="7" class="muted">No invoices or receipts in this period.</td></tr>
        @endforelse
        <tr class="balance">
            <td>{{ \Illuminate\Support\Carbon::parse($to)->format('d M Y') }}</td>
            <td colspan="5">Closing balance</td>
            <td class="num">{{ number_format($closing, 2) }}</td>
        </tr>
    </tbody>
</table>

<p class="closing">
    @if ($total_due > 0.004)
        <strong>Amount due: {{ number_format($total_due, 2) }}</strong> as at {{ \Illuminate\Support\Carbon::parse($to)->format('d F Y') }}.
    @elseif ($total_due < -0.004)
        Your account is in credit by <strong>{{ number_format(abs($total_due), 2) }}</strong>. Nothing is due.
    @else
        Nothing is outstanding on your account as at {{ \Illuminate\Support\Carbon::parse($to)->format('d F Y') }}. Thank you.
    @endif
</p>

@if ($total_due > 0.004)
    <table class="ageing">
        <thead>
            <tr>
                <th>Current</th>
                <th>31–60 days</th>
                <th>61–90 days</th>
                <th>Over 90 days</th>
                <th>Total due</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ number_format($ageing['current'], 2) }}</td>
                <td>{{ number_format($ageing['31-60'], 2) }}</td>
                <td>{{ number_format($ageing['61-90'], 2) }}</td>
                <td>{{ number_format($ageing['90+'], 2) }}</td>
                <td><strong>{{ number_format($total_due, 2) }}</strong></td>
            </tr>
        </tbody>
    </table>
@endif

<p class="muted" style="margin-top: 8mm; font-size: 9.5pt;">
    All figures in the company's accounting currency. If your records disagree with this statement, please
    contact us quoting the document reference.
</p>

@include('pdfs.partials.letterhead-footer', ['company' => $company])

</div>
</body>
</html>
