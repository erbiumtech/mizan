@extends('reports.layout')

@section('title', 'GnuCash Import')

@section('content')
    <h1>GnuCash Import</h1>
    <p class="meta">Import GnuCash CSV exports — Account Tree, Transactions, or Active Register (auto-detected)</p>

    @if(($errors ?? null)?->any())
        <p style="margin-bottom:1rem"><span class="badge badge-bad">{{ $errors->first() }}</span></p>
    @endif

    @if($result)
        <div style="background:#c6f6d5;border-radius:8px;padding:1rem;margin-bottom:1.5rem">
            <p style="font-weight:600;color:#22543d">Import complete ({{ $result['kind'] }})</p>
            <ul style="font-size:.85rem;color:#22543d;margin:.5rem 0 0 1.25rem">
                @foreach($result as $key => $value)
                    @if($key !== 'kind' && $key !== 'errors' && $key !== 'dry_run')
                        <li>{{ str_replace('_', ' ', $key) }}: {{ is_array($value) ? count($value) : $value }}</li>
                    @endif
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('gnucash.preview') }}" enctype="multipart/form-data" class="toolbar">
        @csrf
        <div>
            <label for="csv">GnuCash CSV export</label>
            <input type="file" id="csv" name="csv" accept=".csv,.txt" required style="font-size:.85rem">
        </div>
        <div>
            <label for="target_account_id">Register target account (register exports only)</label>
            <select id="target_account_id" name="target_account_id" style="padding:.4rem .6rem;border:1px solid #cbd5e0;border-radius:6px;font-size:.85rem">
                <option value="">— not a register export —</option>
                @foreach($registerAccounts as $a)
                    <option value="{{ $a->id }}">{{ $a->code }} {{ $a->name }}</option>
                @endforeach
            </select>
        </div>
        <button class="btn btn-primary" type="submit">Upload &amp; Preview</button>
    </form>

    @if($preview)
        <h2 style="font-size:1.05rem;margin:1rem 0 .5rem">Dry-run preview ({{ $preview['kind'] }}) — nothing written yet</h2>
        <table>
            <thead><tr><th>Metric</th><th class="num">Count</th></tr></thead>
            <tbody>
            @foreach($preview as $key => $value)
                @if(!in_array($key, ['kind', 'errors', 'dry_run']))
                    <tr><td>{{ ucfirst(str_replace('_', ' ', $key)) }}</td><td class="num">{{ is_array($value) ? count($value) : $value }}</td></tr>
                @endif
            @endforeach
            </tbody>
        </table>

        @if(!empty($preview['errors']))
            <p style="margin-top:1rem;font-weight:600;color:#c53030">{{ count($preview['errors']) }} row(s) will be skipped:</p>
            <ul style="font-size:.85rem;color:#c53030;margin:.25rem 0 0 1.25rem">
                @foreach(array_slice($preview['errors'], 0, 20) as $error)
                    <li>{{ $error }}</li>
                @endforeach
                @if(count($preview['errors']) > 20)
                    <li>… and {{ count($preview['errors']) - 20 }} more</li>
                @endif
            </ul>
        @endif

        <form method="POST" action="{{ route('gnucash.confirm') }}" style="margin-top:1.5rem">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <button class="btn btn-primary" type="submit"
                    onclick="return confirm('Write these changes to the ledger?')">Confirm Import</button>
        </form>
    @endif
@endsection
