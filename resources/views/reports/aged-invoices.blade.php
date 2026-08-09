@extends('reports.layout')

@section('title', $heading)

@section('content')
    @php($money = fn ($amount) => number_format((float) $amount, 2))
    @php($labels = ['current' => 'Current', '31-60' => '31–60 days', '61-90' => '61–90 days', '90+' => '90+ days'])

    <h1>{{ $heading }}</h1>
    <p class="meta">
        As of {{ \Carbon\Carbon::parse($report['as_of'])->format('d M Y') }} &middot;
        {{ count($report['invoices']) }} {{ \Illuminate\Support\Str::plural('invoice', count($report['invoices'])) }} &middot;
        {{ $money($report['total']) }} outstanding
    </p>

    @unless($pdf)
        <form class="toolbar" method="GET">
            <div>
                <label for="as_of">As of date</label>
                <input type="date" id="as_of" name="as_of" value="{{ $report['as_of'] }}">
            </div>
            <button class="btn btn-primary" type="submit">Run</button>
            <a class="btn btn-secondary" href="{{ request()->fullUrlWithQuery(['format' => 'pdf']) }}">Download PDF</a>
        </form>
    @endunless

    <table>
        <thead>
        <tr>
            <th>Bucket</th>
            <th class="num">Outstanding</th>
        </tr>
        </thead>
        <tbody>
        @foreach($labels as $bucket => $label)
            <tr>
                <td>{{ $label }}</td>
                <td class="num">{{ $money($report['buckets'][$bucket] ?? 0) }}</td>
            </tr>
        @endforeach
        <tr class="grand">
            <td>Total</td>
            <td class="num">{{ $money($report['total']) }}</td>
        </tr>
        </tbody>
    </table>

    <table>
        <thead>
        <tr>
            <th>Invoice</th>
            <th>{{ $isReceivable ? 'Customer' : 'Supplier' }}</th>
            <th>Age</th>
            <th class="num">Days overdue</th>
            <th class="num">Outstanding</th>
        </tr>
        </thead>
        <tbody>
        @forelse($rows as $row)
            <tr>
                <td>{{ $row['invoice_number'] }}</td>
                <td>{{ $row['contact'] }}</td>
                <td>{{ $labels[$row['bucket']] }}</td>
                <td class="num">{{ $row['days_overdue'] ?: '' }}</td>
                <td class="num">{{ $money($row['outstanding']) }}</td>
            </tr>
        @empty
            <tr><td colspan="5" class="meta">Nothing outstanding — every issued invoice is paid.</td></tr>
        @endforelse
        </tbody>
    </table>
@endsection
