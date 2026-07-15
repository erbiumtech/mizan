@extends('reports.layout')

@section('title', 'Profit & Loss')

@section('content')
    <h1>Profit &amp; Loss Statement</h1>
    <p class="meta">
        @if($report['from'])
            {{ \Carbon\Carbon::parse($report['from'])->format('d M Y') }} —
        @else
            Up to
        @endif
        {{ \Carbon\Carbon::parse($report['to'])->format('d M Y') }}
    </p>

    @unless($pdf)
        <form class="toolbar" method="GET">
            <div>
                <label for="from">From</label>
                <input type="date" id="from" name="from" value="{{ $report['from'] }}">
            </div>
            <div>
                <label for="to">To</label>
                <input type="date" id="to" name="to" value="{{ $report['to'] }}">
            </div>
            <button class="btn btn-primary" type="submit">Run</button>
            <a class="btn btn-secondary" href="{{ request()->fullUrlWithQuery(['format' => 'pdf']) }}">Download PDF</a>
        </form>
    @endunless

    <table>
        <thead>
        <tr>
            <th>Code</th>
            <th>Account</th>
            <th class="num">Amount</th>
        </tr>
        </thead>
        <tbody>
        <tr class="section-row"><td colspan="3">Income</td></tr>
        @forelse($report['income']['rows'] as $row)
            <tr>
                <td>{{ $row['code'] }}</td>
                <td>{{ $row['name'] }}</td>
                <td class="num">{{ number_format($row['amount'], 2) }}</td>
            </tr>
        @empty
            <tr><td colspan="3" style="color:#a0aec0">No income in this period</td></tr>
        @endforelse
        <tr class="subtotal">
            <td colspan="2">Total Income</td>
            <td class="num">{{ number_format($report['income']['total'], 2) }}</td>
        </tr>

        <tr class="section-row"><td colspan="3">Expenses</td></tr>
        @forelse($report['expenses']['rows'] as $row)
            <tr>
                <td>{{ $row['code'] }}</td>
                <td>{{ $row['name'] }}</td>
                <td class="num">{{ number_format($row['amount'], 2) }}</td>
            </tr>
        @empty
            <tr><td colspan="3" style="color:#a0aec0">No expenses in this period</td></tr>
        @endforelse
        <tr class="subtotal">
            <td colspan="2">Total Expenses</td>
            <td class="num">{{ number_format($report['expenses']['total'], 2) }}</td>
        </tr>

        <tr class="grand">
            <td colspan="2">Net {{ $report['is_profit'] ? 'Profit' : 'Loss' }}</td>
            <td class="num {{ $report['is_profit'] ? 'profit' : 'loss' }}">{{ number_format(abs($report['net_profit']), 2) }}</td>
        </tr>
        </tbody>
    </table>
@endsection
