@extends('reports.layout')

@section('title', 'Trial Balance')

@section('content')
    <h1>Trial Balance</h1>
    <p class="meta">
        As of {{ \Carbon\Carbon::parse($report['as_of'])->format('d M Y') }} &middot;
        @if($report['balanced'])
            <span class="badge badge-ok">Balanced</span>
        @else
            <span class="badge badge-bad">Out of balance</span>
        @endif

        {{-- A book can balance and still be half-migrated: unbalanced opening
             balances pile up in Opening Balance Equity rather than showing as an
             imbalance, so it gets its own badge. --}}
        @if(($obe = $report['opening_balance_equity'] ?? null) && ! $obe['is_clear'])
            &middot; <span class="badge badge-bad">Opening Balance Equity {{ number_format($obe['balance'], 2) }}</span>
        @elseif($obe && $obe['in_use'])
            &middot; <span class="badge badge-ok">Opening balances clear</span>
        @endif
    </p>

    @if(($report['opening_balance_equity'] ?? null) && ! $report['opening_balance_equity']['is_clear'])
        <p class="meta" style="color:#742a2a">
            Account {{ $report['opening_balance_equity']['code'] }} (Opening Balance Equity) still holds
            {{ number_format($report['opening_balance_equity']['balance'], 2) }}. Every opening balance credits
            this account, so a leftover means some accounts' opening figures have not been entered yet — the
            totals below can balance and still be incomplete.
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
            <th class="num">Debit</th>
            <th class="num">Credit</th>
        </tr>
        </thead>
        <tbody>
        @foreach($report['sections'] as $section)
            <tr class="section-row"><td colspan="4">{{ $section['type'] }}</td></tr>
            @foreach($section['rows'] as $row)
                <tr>
                    <td>{{ $row['code'] }}</td>
                    <td>{{ $row['name'] }}</td>
                    <td class="num">{{ $row['debit'] ? number_format($row['debit'], 2) : '' }}</td>
                    <td class="num">{{ $row['credit'] ? number_format($row['credit'], 2) : '' }}</td>
                </tr>
            @endforeach
            <tr class="subtotal">
                <td colspan="2">Total {{ $section['type'] }}</td>
                <td class="num">{{ number_format($section['total_debits'], 2) }}</td>
                <td class="num">{{ number_format($section['total_credits'], 2) }}</td>
            </tr>
        @endforeach
        <tr class="grand">
            <td colspan="2">Grand Total</td>
            <td class="num" @unless($report['balanced']) style="color:#c53030" @endunless>{{ number_format($report['total_debits'], 2) }}</td>
            <td class="num" @unless($report['balanced']) style="color:#c53030" @endunless>{{ number_format($report['total_credits'], 2) }}</td>
        </tr>
        </tbody>
    </table>
@endsection
