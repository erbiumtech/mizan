@extends('reports.layout')

@section('title', 'Bank Payment File')

@section('content')
    <h1>Bank Payment File (iPayments CSV)</h1>
    <p class="meta">Salaries, rent, food and other payments in one iPayments file &middot; Fiscal year {{ $fiscalYear->name }}</p>

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
            <label for="type">Transaction type</label>
            <select id="type" name="type" style="padding:.4rem .6rem;border:1px solid #cbd5e0;border-radius:6px;font-size:.85rem">
                <option value="">All types</option>
                @foreach($types as $t)
                    <option value="{{ $t->code }}" @selected($t->code === $typeCode)>{{ $t->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="value_date">Value date</label>
            <input type="date" id="value_date" name="value_date" value="{{ $valueDate }}">
        </div>
        <button class="btn btn-primary" type="submit">Preview</button>
        @if($rows->isNotEmpty())
            <a class="btn btn-secondary" href="{{ request()->fullUrlWithQuery(['download' => 1]) }}"
               onclick="return confirm('Download marks these {{ $rows->count() }} payments as exported. Continue?')">Download CSV</a>
        @endif
    </form>

    @if($rows->isEmpty())
        <p style="color:#a0aec0">No pending (draft/approved) payments match. Salary payments are generated automatically from payslips; add rent/food payments on the Nova Payments screen.</p>
    @else
        <table>
            <thead>
            <tr>
                <th>#</th>
                <th>Payee</th>
                <th>Type</th>
                <th>Payment Type</th>
                <th>Account / IBAN</th>
                <th>Details</th>
                <th>Status</th>
                <th class="num">Amount</th>
            </tr>
            </thead>
            <tbody>
            @foreach($rows->groupBy(fn ($p) => $p->transactionType->name) as $typeName => $group)
                <tr class="section-row"><td colspan="8">{{ $typeName }}</td></tr>
                @foreach($group as $p)
                    @php($b = $p->beneficiaryDetails())
                    <tr>
                        <td>{{ $p->id }}</td>
                        <td>{{ $b['name'] }}</td>
                        <td>{{ $p->transactionType->name }}</td>
                        <td><span class="badge badge-ok">{{ $p->resolvedPaymentType() }}</span></td>
                        <td>
                            @if($b['account'])
                                {{ $b['account'] }}
                            @else
                                <span class="badge badge-bad">missing</span>
                            @endif
                        </td>
                        <td>{{ $p->details }}</td>
                        <td>{{ $p->status }}</td>
                        <td class="num">{{ number_format($p->amount, 2) }}</td>
                    </tr>
                @endforeach
                <tr class="subtotal">
                    <td colspan="7">Total {{ $typeName }} ({{ $group->count() }})</td>
                    <td class="num">{{ number_format($group->sum('amount'), 2) }}</td>
                </tr>
            @endforeach
            <tr class="grand">
                <td colspan="7">Grand Total ({{ $rows->count() }} payments)</td>
                <td class="num">{{ number_format($rows->sum('amount'), 2) }}</td>
            </tr>
            </tbody>
        </table>
        @if($rows->contains(fn ($p) => ! $p->beneficiaryDetails()['account']))
            <p style="margin-top:1rem;font-size:.85rem;color:#c53030">Some payees have no bank account/IBAN on file — fill these in before uploading.</p>
        @endif
    @endif
@endsection
