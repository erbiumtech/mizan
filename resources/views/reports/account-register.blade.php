@extends('reports.layout')

@section('title', 'Account Register')

@section('content')
    <h1>Account Register</h1>
    <p class="meta">GnuCash-style register — every transaction from one screen</p>

    {{-- Account tabs --}}
    <div style="display:flex;gap:.5rem;margin-bottom:1rem;border-bottom:2px solid #e2e8f0;flex-wrap:wrap">
        @foreach($accounts as $a)
            <a href="{{ route('register.show', ['account' => $a->id]) }}"
               style="padding:.5rem 1rem;border-radius:6px 6px 0 0;text-decoration:none;font-size:.9rem;{{ $a->id === $account->id ? 'background:#4c51bf;color:#fff;font-weight:600' : 'background:#edf2f7;color:#2d3748' }}">
                {{ $a->code }} {{ $a->name }}
            </a>
        @endforeach
    </div>

    <form class="toolbar" method="GET" action="{{ route('register.show', ['account' => $account->id]) }}">
        <div>
            <label for="from">From</label>
            <input type="date" id="from" name="from" value="{{ $from }}">
        </div>
        <div>
            <label for="to">To</label>
            <input type="date" id="to" name="to" value="{{ $to }}">
        </div>
        <button class="btn btn-primary" type="submit">Filter</button>
    </form>

    @if(session('status'))
        <p style="margin-bottom:1rem"><span class="badge badge-ok">{{ session('status') }}</span></p>
    @endif
    @if(($errors ?? null)?->any())
        <p style="margin-bottom:1rem"><span class="badge badge-bad">{{ $errors->first() }}</span></p>
    @endif

    <table>
        <thead>
        <tr>
            <th>Date</th>
            <th>Num</th>
            <th>Description</th>
            <th>Transfer</th>
            <th>R</th>
            <th class="num">Debit</th>
            <th class="num">Credit</th>
            <th class="num">Balance</th>
        </tr>
        </thead>
        <tbody>
        @if($from)
            <tr>
                <td colspan="7" style="color:#718096">Opening balance</td>
                <td class="num">{{ number_format($ledger['opening_balance'], 2) }}</td>
            </tr>
        @endif
        @forelse($ledger['rows'] as $row)
            <tr>
                <td>{{ \Carbon\Carbon::parse($row['date'])->format('d/m/Y') }}</td>
                <td><a href="{{ url('/nova/resources/journal-entries/'.$row['entry_id']) }}" style="color:#4c51bf;text-decoration:none">{{ $row['num'] }}</a></td>
                <td>{{ $row['description'] }}</td>
                <td>{{ $row['transfer'] }}</td>
                <td>{{ $row['reconciled'] }}</td>
                <td class="num">{{ $row['debit'] ? number_format($row['debit'], 2) : '' }}</td>
                <td class="num">{{ $row['credit'] ? number_format($row['credit'], 2) : '' }}</td>
                <td class="num">{{ number_format($row['balance'], 2) }}</td>
            </tr>
        @empty
            <tr><td colspan="8" style="color:#a0aec0">No posted transactions in this range.</td></tr>
        @endforelse
        <tr class="grand">
            <td colspan="7">Closing balance</td>
            <td class="num">{{ number_format($ledger['closing_balance'], 2) }}</td>
        </tr>
        </tbody>
    </table>

    @if($canAdd)
        <form method="POST" action="{{ route('register.store', ['account' => $account->id]) }}" style="margin-top:1.5rem;background:#f7fafc;border:1px solid #e2e8f0;border-radius:8px;padding:1rem">
            @csrf
            <input type="hidden" name="from" value="{{ $from }}">
            <input type="hidden" name="to" value="{{ $to }}">
            <p style="font-weight:600;font-size:.9rem;margin-bottom:.75rem">Add transaction</p>
            <div style="display:grid;grid-template-columns:130px 90px 1fr 1fr 110px 110px auto;gap:.5rem;align-items:end">
                <div>
                    <label style="display:block;font-size:.7rem;color:#4a5568">Date</label>
                    <input type="date" name="date" value="{{ old('date', now()->toDateString()) }}" required style="width:100%;padding:.4rem;border:1px solid #cbd5e0;border-radius:6px;font-size:.85rem">
                </div>
                <div>
                    <label style="display:block;font-size:.7rem;color:#4a5568">Num</label>
                    <input type="text" name="num" value="{{ old('num') }}" style="width:100%;padding:.4rem;border:1px solid #cbd5e0;border-radius:6px;font-size:.85rem">
                </div>
                <div>
                    <label style="display:block;font-size:.7rem;color:#4a5568">Description</label>
                    <input type="text" name="description" value="{{ old('description') }}" required style="width:100%;padding:.4rem;border:1px solid #cbd5e0;border-radius:6px;font-size:.85rem">
                </div>
                <div>
                    <label style="display:block;font-size:.7rem;color:#4a5568">Transfer</label>
                    <select name="transfer_account_id" required style="width:100%;padding:.4rem;border:1px solid #cbd5e0;border-radius:6px;font-size:.85rem">
                        <option value="">— select account —</option>
                        @foreach($transferOptions->groupBy('type') as $type => $options)
                            <optgroup label="{{ ucfirst($type) }}">
                                @foreach($options as $opt)
                                    <option value="{{ $opt['id'] }}" @selected(old('transfer_account_id') == $opt['id'])>{{ $opt['label'] }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label style="display:block;font-size:.7rem;color:#4a5568">Debit (in)</label>
                    <input type="number" step="0.01" min="0" name="debit" value="{{ old('debit') }}" style="width:100%;padding:.4rem;border:1px solid #cbd5e0;border-radius:6px;font-size:.85rem;text-align:right">
                </div>
                <div>
                    <label style="display:block;font-size:.7rem;color:#4a5568">Credit (out)</label>
                    <input type="number" step="0.01" min="0" name="credit" value="{{ old('credit') }}" style="width:100%;padding:.4rem;border:1px solid #cbd5e0;border-radius:6px;font-size:.85rem;text-align:right">
                </div>
                <button class="btn btn-primary" type="submit">Save</button>
            </div>
            <p style="font-size:.75rem;color:#718096;margin-top:.5rem">Enter the amount in <strong>Debit</strong> for money into {{ $account->name }}, or <strong>Credit</strong> for money out. The entry posts immediately as a balanced 2-line journal entry.</p>
        </form>
    @endif
@endsection
