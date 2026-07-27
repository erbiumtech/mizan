<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $invoice->invoice_number }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #222; }
        .header { display: flex; justify-content: space-between; margin-bottom: 24px; }
        h1 { font-size: 20px; margin: 0 0 4px; }
        .muted { color: #666; }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        th, td { padding: 6px 8px; border-bottom: 1px solid #ddd; text-align: left; }
        th { background: #f4f4f4; }
        .num { text-align: right; }
        .totals { margin-top: 12px; width: 40%; margin-left: auto; }
        .totals td { border: none; padding: 3px 8px; }
        .totals .grand { font-weight: bold; border-top: 2px solid #222; }
        .status { text-transform: uppercase; letter-spacing: 1px; font-weight: bold; }
    </style>

    @if (($pdfEngine ?? null) === 'dompdf')
        @include('pdfs.partials.dompdf-invoice')
    @endif
</head>
<body>
    <div class="header">
        <div>
            <h1>{{ $invoice->kind === 'purchase' ? 'Bill' : 'Invoice' }} {{ $invoice->invoice_number }}</h1>
            <div class="muted">Date: {{ $invoice->invoice_date->format('d M Y') }}</div>
            @if ($invoice->due_date)
                <div class="muted">Due: {{ $invoice->due_date->format('d M Y') }}</div>
            @endif
            <div class="status">{{ str_replace('_', ' ', $invoice->status) }}</div>
        </div>
        <div>
            <strong>{{ $invoice->kind === 'purchase' ? 'Supplier' : 'Bill To' }}</strong><br>
            {{ $invoice->contact->name }}<br>
            @if ($invoice->contact->address_line_1) {{ $invoice->contact->address_line_1 }}<br> @endif
            @if ($invoice->contact->address_line_2) {{ $invoice->contact->address_line_2 }}<br> @endif
            @if ($invoice->contact->ntn) NTN: {{ $invoice->contact->ntn }} @endif
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Description</th>
                <th class="num">Qty</th>
                <th class="num">Unit Price</th>
                <th class="num">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice->lines as $line)
                <tr>
                    <td>{{ $line->description }}@if ($line->product) <span class="muted">({{ $line->product->sku }})</span>@endif</td>
                    <td class="num">{{ number_format($line->quantity, 2) }}</td>
                    <td class="num">{{ number_format($line->unit_price, 2) }}</td>
                    <td class="num">{{ number_format($line->line_total, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr><td>Subtotal</td><td class="num">{{ number_format($invoice->subtotal, 2) }}</td></tr>
        <tr><td>Tax</td><td class="num">{{ number_format($invoice->tax_amount, 2) }}</td></tr>
        <tr class="grand"><td>Total (PKR)</td><td class="num">{{ number_format($invoice->total, 2) }}</td></tr>
        <tr><td>Paid</td><td class="num">{{ number_format($invoice->amount_paid, 2) }}</td></tr>
        <tr><td>Outstanding</td><td class="num">{{ number_format($invoice->outstanding(), 2) }}</td></tr>
    </table>

    @if ($invoice->memo)
        <p class="muted">{{ $invoice->memo }}</p>
    @endif
</body>
</html>
