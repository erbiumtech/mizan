@extends('reports.layout')

@section('title', 'Petty Cash Book')

@section('content')
    <h1>Petty Cash Book</h1>
    <p class="meta">
        {{ $summary['month'] }} &middot; imprest float {{ number_format($summary['float_amount'], 2) }} &middot;
        @if($summary['replenished'])
            <span class="badge badge-ok">Replenished</span>
        @else
            <span class="badge badge-bad">Open</span>
        @endif
    </p>

    <div style="display:flex;gap:1rem;align-items:end;flex-wrap:wrap;margin-bottom:1rem">
        <form class="toolbar" method="GET" style="margin-bottom:0">
            <div>
                <label for="month">Month</label>
                <input type="month" id="month" name="month" value="{{ $monthValue }}">
            </div>
            <button class="btn btn-primary" type="submit">Show</button>
        </form>
        @if($canReplenish && !$summary['replenished'] && $summary['closing_balance'] < $summary['float_amount'])
            <form method="POST" action="{{ route('petty-cash.replenish', ['month' => $monthValue]) }}"
                  onsubmit="return confirm('Create a replenishment payment of {{ number_format($summary['float_amount'] - $summary['closing_balance'], 2) }} to the custodian?')">
                @csrf
                <input type="hidden" name="month" value="{{ $monthValue }}">
                <button class="btn btn-secondary" type="submit">Replenish Month ({{ number_format($summary['float_amount'] - $summary['closing_balance'], 2) }})</button>
            </form>
        @endif
    </div>

    @if(session('status'))
        <p style="margin-bottom:1rem"><span class="badge badge-ok">{{ session('status') }}</span></p>
    @endif
    @if(($errors ?? null)?->any())
        <p style="margin-bottom:1rem"><span class="badge badge-bad">{{ $errors->first() }}</span></p>
    @endif

    <div style="display:grid;grid-template-columns:1fr 2fr;gap:1.5rem;align-items:start">
        {{-- Received side --}}
        <table>
            <thead>
            <tr><th colspan="3" style="text-align:center">Received</th></tr>
            <tr><th>Date</th><th>Details</th><th class="num">Amount</th></tr>
            </thead>
            <tbody>
            <tr>
                <td>{{ \Carbon\Carbon::parse($monthValue.'-01')->format('M j') }}</td>
                <td>b/d</td>
                <td class="num">{{ number_format($summary['opening_balance'], 2) }}</td>
            </tr>
            @foreach($summary['received'] as $row)
                <tr>
                    <td>{{ \Carbon\Carbon::parse($row['date'])->format('M j') }}</td>
                    <td>{{ $row['details'] }}</td>
                    <td class="num">{{ number_format($row['amount'], 2) }}</td>
                </tr>
            @endforeach
            <tr class="grand">
                <td colspan="2">Total received</td>
                <td class="num">{{ number_format($summary['opening_balance'] + $summary['received_total'], 2) }}</td>
            </tr>
            </tbody>
        </table>

        {{-- Paid side with analysis columns --}}
        <div style="overflow-x:auto">
            <table>
                <thead>
                <tr><th colspan="{{ 4 + count($summary['columns']) }}" style="text-align:center">Paid</th></tr>
                <tr>
                    <th>Date</th>
                    <th>Voucher</th>
                    <th>Details</th>
                    <th class="num">Total Paid</th>
                    @foreach($summary['columns'] as $col)
                        <th class="num">{{ $col }}</th>
                    @endforeach
                </tr>
                </thead>
                <tbody>
                @forelse($summary['paid'] as $row)
                    <tr>
                        <td>{{ \Carbon\Carbon::parse($row['date'])->format('M j') }}</td>
                        <td>{{ $row['voucher_no'] }}</td>
                        <td>{{ $row['details'] }}</td>
                        <td class="num">{{ number_format($row['amount'], 2) }}</td>
                        @foreach($summary['columns'] as $col)
                            <td class="num">{{ $row['column'] === $col ? number_format($row['amount'], 2) : '' }}</td>
                        @endforeach
                    </tr>
                @empty
                    <tr><td colspan="{{ 4 + count($summary['columns']) }}" style="color:#a0aec0">No vouchers this month.</td></tr>
                @endforelse
                <tr class="subtotal">
                    <td colspan="3">c/d (closing balance)</td>
                    <td class="num">{{ number_format($summary['closing_balance'], 2) }}</td>
                    @foreach($summary['columns'] as $col)<td></td>@endforeach
                </tr>
                <tr class="grand">
                    <td colspan="3">Totals</td>
                    <td class="num">{{ number_format($summary['paid_total'], 2) }}</td>
                    @foreach($summary['columns'] as $col)
                        <td class="num">{{ number_format($summary['column_totals'][$col] ?? 0, 2) }}</td>
                    @endforeach
                </tr>
                </tbody>
            </table>
        </div>
    </div>

    @if($summary['closing_balance'] !== $summary['ledger_balance'])
        <p style="margin-top:1rem"><span class="badge badge-bad">Book c/d {{ number_format($summary['closing_balance'], 2) }} disagrees with ledger 1150 balance {{ number_format($summary['ledger_balance'], 2) }} — investigate manual entries.</span></p>
    @endif

    @if($canCreate && !$summary['replenished'])
        <form method="POST" action="{{ route('petty-cash.voucher') }}" style="margin-top:1.5rem;background:#f7fafc;border:1px solid #e2e8f0;border-radius:8px;padding:1rem">
            @csrf
            <p style="font-weight:600;font-size:.9rem;margin-bottom:.75rem">Add voucher</p>
            <div style="display:grid;grid-template-columns:140px 1fr 1fr 120px auto;gap:.5rem;align-items:end">
                <div>
                    <label style="display:block;font-size:.7rem;color:#4a5568">Date</label>
                    <input type="date" name="date" value="{{ old('date', now()->toDateString()) }}" required style="width:100%;padding:.4rem;border:1px solid #cbd5e0;border-radius:6px;font-size:.85rem">
                </div>
                <div>
                    <label style="display:block;font-size:.7rem;color:#4a5568">Details</label>
                    <input type="text" name="details" value="{{ old('details') }}" required style="width:100%;padding:.4rem;border:1px solid #cbd5e0;border-radius:6px;font-size:.85rem">
                </div>
                <div>
                    <label style="display:block;font-size:.7rem;color:#4a5568">Category</label>
                    <select name="transaction_type_id" required style="width:100%;padding:.4rem;border:1px solid #cbd5e0;border-radius:6px;font-size:.85rem">
                        <option value="">— select —</option>
                        @foreach($types as $t)
                            <option value="{{ $t->id }}" @selected(old('transaction_type_id') == $t->id)>{{ $t->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label style="display:block;font-size:.7rem;color:#4a5568">Amount</label>
                    <input type="number" step="0.01" min="0.01" name="amount" value="{{ old('amount') }}" required style="width:100%;padding:.4rem;border:1px solid #cbd5e0;border-radius:6px;font-size:.85rem;text-align:right">
                </div>
                <button class="btn btn-primary" type="submit">Save</button>
            </div>
        </form>
        @if($canReplenish)
            <form method="POST" action="{{ route('petty-cash.topup') }}" class="toolbar" style="margin-top:1rem">
                @csrf
                <div>
                    <label>Top-up date</label>
                    <input type="date" name="date" value="{{ now()->toDateString() }}" required>
                </div>
                <div>
                    <label>Top-up amount</label>
                    <input type="number" step="0.01" min="0.01" name="amount" required style="padding:.4rem .6rem;border:1px solid #cbd5e0;border-radius:6px;font-size:.85rem">
                </div>
                <button class="btn btn-secondary" type="submit">Top Up Float (direct from bank)</button>
            </form>
        @endif
    @endif
@endsection
