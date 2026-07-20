@extends('reports.layout')

@section('title', 'Salary Bank File')

@section('content')
    <h1>Salary Bank File (iPayments CSV)</h1>
    <p class="meta">Standard Chartered iPayments bulk-payment export — UTF-8, comma-delimited &middot; Fiscal year {{ $fiscalYear->name }}</p>

    <form class="toolbar" method="GET">
        <div>
            <label for="month">Salary month</label>
            <select id="month" name="month" style="padding:.4rem .6rem;border:1px solid #cbd5e0;border-radius:6px;font-size:.85rem">
                @foreach($months as $m)
                    <option value="{{ $m }}" @selected($m === $month)>{{ $m }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="value_date">Payment/value date</label>
            <input type="date" id="value_date" name="value_date" value="{{ $valueDate }}">
        </div>
        <button class="btn btn-primary" type="submit">Preview</button>
        @if($payments)
            <a class="btn btn-secondary" href="{{ request()->fullUrlWithQuery(['download' => 1]) }}">Download CSV</a>
        @endif
    </form>

    @if(!$payments)
        <p style="color:#a0aec0">No payslips found for {{ $month }}.</p>
    @else
        <table>
            <thead>
            <tr>
                <th>#</th>
                <th>Employee</th>
                <th>Account / IBAN</th>
                <th>Bank</th>
                <th>Details</th>
                <th class="num">Amount</th>
            </tr>
            </thead>
            <tbody>
            @foreach($payments as $i => $p)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $p['name'] }}</td>
                    <td>
                        @if($p['account'])
                            {{ $p['account'] }}
                        @else
                            <span class="badge badge-bad">missing</span>
                        @endif
                    </td>
                    <td>{{ $p['bank_name'] ?: '—' }}</td>
                    <td>{{ $p['details'] }}</td>
                    <td class="num">{{ number_format($p['amount'], 2) }}</td>
                </tr>
            @endforeach
            <tr class="grand">
                <td colspan="5">Total ({{ count($payments) }} payments)</td>
                <td class="num">{{ number_format(collect($payments)->sum('amount'), 2) }}</td>
            </tr>
            </tbody>
        </table>
        @if(collect($payments)->contains(fn ($p) => ! $p['account']))
            <p style="margin-top:1rem;font-size:.85rem;color:#c53030">Some employees have no bank account/IBAN on file — their rows will export with a blank account. Fill these in on the Employee record before uploading.</p>
        @endif
    @endif
@endsection
