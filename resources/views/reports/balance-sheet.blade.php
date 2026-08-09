@extends('reports.layout')

@section('title', 'Balance Sheet')

@section('content')
    @php
        $money = fn ($amount) => number_format((float) $amount, 2);
    @endphp

    <h1>Balance Sheet</h1>
    <p class="meta">
        As of {{ \Carbon\Carbon::parse($report['as_of'])->format('d M Y') }} &middot;
        @if($report['balanced'])
            <span class="badge badge-ok">Balanced</span>
        @else
            <span class="badge badge-bad">Out of balance</span>
        @endif

        {{-- A statement can balance and still be half-migrated: unbalanced opening
             balances pile up in Opening Balance Equity rather than showing as an
             imbalance, so it gets its own badge here as on the trial balance. --}}
        @if(($obe = $report['opening_balance_equity'] ?? null) && ! $obe['is_clear'])
            &middot; <span class="badge badge-bad">Opening Balance Equity {{ $money($obe['balance']) }}</span>
        @elseif($obe && $obe['in_use'])
            &middot; <span class="badge badge-ok">Opening balances clear</span>
        @endif
    </p>

    @if(($report['opening_balance_equity'] ?? null) && ! $report['opening_balance_equity']['is_clear'])
        <p class="meta" style="color:#742a2a">
            Account {{ $report['opening_balance_equity']['code'] }} (Opening Balance Equity) still holds
            {{ $money($report['opening_balance_equity']['balance']) }}. Every opening balance credits this
            account, so a leftover means some accounts' opening figures have not been entered yet — this
            statement can balance and still be incomplete.
        </p>
    @endif

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
            <th>Code</th>
            <th>Account</th>
            <th class="num">Amount</th>
        </tr>
        </thead>
        <tbody>
        <tr class="section-row"><td colspan="3">Assets</td></tr>
        @forelse($report['assets']['rows'] as $row)
            <tr>
                <td>{{ $row['code'] }}</td>
                <td>{{ $row['name'] }}</td>
                <td class="num">{{ $money($row['amount']) }}</td>
            </tr>
        @empty
            <tr><td colspan="3" class="meta">No assets</td></tr>
        @endforelse
        <tr class="subtotal">
            <td colspan="2">Total assets</td>
            <td class="num">{{ $money($report['assets']['total']) }}</td>
        </tr>

        <tr class="section-row"><td colspan="3">Liabilities</td></tr>
        @forelse($report['liabilities']['rows'] as $row)
            <tr>
                <td>{{ $row['code'] }}</td>
                <td>{{ $row['name'] }}</td>
                <td class="num">{{ $money($row['amount']) }}</td>
            </tr>
        @empty
            <tr><td colspan="3" class="meta">No liabilities</td></tr>
        @endforelse
        <tr class="subtotal">
            <td colspan="2">Total liabilities</td>
            <td class="num">{{ $money($report['liabilities']['total']) }}</td>
        </tr>

        <tr class="section-row"><td colspan="3">Equity</td></tr>
        @foreach($report['equity']['rows'] as $row)
            <tr>
                <td>{{ $row['code'] }}</td>
                <td>{{ $row['name'] }}</td>
                <td class="num">{{ $money($row['amount']) }}</td>
            </tr>
        @endforeach

        {{-- Its own line, because it is not in any account yet. Income and expense
             accounts are zeroed into Retained Earnings at year-end, so between
             closes the profit so far lives in them and nowhere else. --}}
        <tr>
            <td></td>
            <td>Earnings for the period, not yet closed</td>
            <td class="num">{{ $money($report['retained_earnings_for_period']) }}</td>
        </tr>

        <tr class="subtotal">
            <td colspan="2">Total equity</td>
            <td class="num">{{ $money($report['equity_total']) }}</td>
        </tr>

        <tr class="grand">
            <td colspan="2">Liabilities and equity</td>
            <td class="num" @unless($report['balanced']) style="color:#c53030" @endunless>
                {{ $money($report['liabilities_and_equity_total']) }}
            </td>
        </tr>
        <tr class="grand">
            <td colspan="2">Total assets</td>
            <td class="num" @unless($report['balanced']) style="color:#c53030" @endunless>
                {{ $money($report['assets']['total']) }}
            </td>
        </tr>
        </tbody>
    </table>

    @unless($report['balanced'])
        <p class="meta" style="color:#c53030">
            Assets do not equal liabilities plus equity. This statement is derived from the trial balance, so
            the two cannot disagree with each other — an imbalance here means the ledger itself is out, and the
            trial balance for the same date will say so too.
        </p>
    @endunless
@endsection
