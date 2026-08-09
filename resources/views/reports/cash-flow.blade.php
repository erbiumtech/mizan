@extends('reports.layout')

@section('title', 'Cash Flow')

@section('content')
    @php($money = fn ($amount) => number_format((float) $amount, 2))

    <h1>Cash Flow</h1>
    <p class="meta">
        {{ $report['from'] ? \Carbon\Carbon::parse($report['from'])->format('d M Y') : 'Start of the book' }}
        to {{ \Carbon\Carbon::parse($report['to'])->format('d M Y') }} &middot;
        indirect method &middot;
        @if($report['reconciles'])
            <span class="badge badge-ok">Reconciles</span>
        @else
            <span class="badge badge-bad">Does not reconcile</span>
        @endif
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
        <tbody>
        <tr class="section-row"><td colspan="2">Operating</td></tr>
        <tr>
            <td>Net income</td>
            <td class="num">{{ $money($report['operating']['net_income']) }}</td>
        </tr>
        @if($report['operating']['depreciation'] != 0)
            <tr>
                <td>Depreciation, added back</td>
                <td class="num">{{ $money($report['operating']['depreciation']) }}</td>
            </tr>
        @endif
        @foreach($report['operating']['working_capital'] as $row)
            <tr>
                <td>{{ $row['code'] }} {{ $row['name'] }}</td>
                <td class="num">{{ $money($row['amount']) }}</td>
            </tr>
        @endforeach
        <tr class="subtotal">
            <td>Cash from operating</td>
            <td class="num">{{ $money($report['operating']['total']) }}</td>
        </tr>

        @foreach([['Investing', $report['investing']], ['Financing', $report['financing']]] as [$heading, $section])
            <tr class="section-row"><td colspan="2">{{ $heading }}</td></tr>
            @forelse($section['rows'] as $row)
                <tr>
                    <td>{{ $row['code'] }} {{ $row['name'] }}</td>
                    <td class="num">{{ $money($row['amount']) }}</td>
                </tr>
            @empty
                <tr><td colspan="2" class="meta">No {{ strtolower($heading) }} activity</td></tr>
            @endforelse
            <tr class="subtotal">
                <td>Cash from {{ strtolower($heading) }}</td>
                <td class="num">{{ $money($section['total']) }}</td>
            </tr>
        @endforeach

        <tr class="grand">
            <td>Net change in cash</td>
            <td class="num">{{ $money($report['net_change']) }}</td>
        </tr>
        <tr>
            <td>Opening cash</td>
            <td class="num">{{ $money($report['opening_cash']) }}</td>
        </tr>
        <tr class="grand">
            <td>Closing cash</td>
            <td class="num">{{ $money($report['closing_cash']) }}</td>
        </tr>
        </tbody>
    </table>
@endsection
