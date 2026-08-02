{{--
    The month's bill, set out the way the client has always read it: a row per
    employee broken into what makes up their cost, the office expenses under it,
    then what came off the advances and what it comes to in their currency.

    One template for the screen and the PDF — $pdf says which. Anything that only
    makes sense on screen (the toolbar) is behind @unless($pdf), so the printed
    copy and the page can never drift into being two different documents.
--}}
@extends('reports.layout')

@section('title', 'Bill — '.$run->periodLabel())

@section('back')
    <a class="back" href="{{ $backUrl }}">&larr; Back to billing</a>
@endsection

@section('styles')
    {{-- Wider than the layout's default: this one is a spreadsheet, not a list. --}}
    .report { max-width: 1200px; }
    table.statement-head { width: 100%; margin-bottom: .5rem; }
    table.statement-head td { border: none; padding: 0; vertical-align: top; }
    table.statement-head .who { font-size: 1rem; font-weight: 600; color: #2d3748; text-align: right; }
    table.grid th, table.grid td { padding: .4rem .6rem; }
    table.grid tbody tr:nth-child(even) td { background: #fbfcfd; }
    .row-number { color: #a0aec0; width: 2.5rem; }
    .section-heading { font-size: 1.05rem; font-weight: 700; margin: 2rem 0 .5rem; }
    .empty { color: #718096; font-size: .875rem; padding: .5rem 0; }
    .credit td { color: #742a2a; }
    table.totals { width: 100%; margin-top: 1.5rem; border-top: 2px solid #2d3748; }
    table.totals td { border: none; font-size: .9rem; padding: .15rem .6rem; }
    table.totals tr:first-child td { padding-top: .75rem; }
    table.totals .grand td { font-size: 1.1rem; font-weight: 700; padding-top: .5rem; }
    table.totals .quoted td { color: #4c51bf; font-weight: 600; }
@endsection

@section('content')
    @php
        $money = fn ($amount) => number_format((float) $amount, 2);
        $columns = $statement['columns'];
    @endphp

    {{-- Tables rather than flexbox throughout this page: Dompdf, which renders
         the PDF wherever Node is not installed, understands neither flex nor
         grid, and a statement that only lays out correctly on one of the two
         engines is a statement nobody can trust to print. --}}
    <table class="statement-head">
        <tr>
        <td>
            <h1>Bill for {{ $run->periodLabel() }}</h1>
            <p class="meta">
                {{ $run->contact?->name ?? 'No client' }}
                @if ($run->invoice)
                    &middot; {{ $run->invoice->invoice_number }}
                    <span class="badge {{ $run->invoice->isDraft() ? 'badge-bad' : 'badge-ok' }}">
                        {{ $run->invoice->isDraft() ? 'draft' : $run->invoice->status }}
                    </span>
                @else
                    &middot; <span class="badge badge-bad">no invoice built</span>
                @endif
            </p>
        </td>
        <td class="who">{{ $company?->name }}</td>
        </tr>
    </table>

    @unless($pdf)
        <div class="toolbar">
            <a class="btn btn-primary" href="{{ request()->fullUrlWithQuery(['format' => 'pdf']) }}" target="_blank" rel="noopener">
                Open PDF
            </a>
            <a class="btn btn-secondary" href="{{ request()->fullUrlWithQuery(['format' => 'pdf', 'download' => 1]) }}">
                Download PDF
            </a>
        </div>
    @endunless

    <div class="section-heading">Employees</div>

    @if ($statement['employees'] === [])
        <p class="empty">No payslips for this month.</p>
    @else
        <table class="grid">
            <thead>
            <tr>
                <th class="row-number"></th>
                <th>Employee Name</th>
                @foreach ($columns as $label)
                    <th class="num">{{ $label }}</th>
                @endforeach
                <th class="num">Total</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($statement['employees'] as $index => $employee)
                <tr>
                    <td class="row-number">{{ $index + 1 }}</td>
                    <td>{{ $employee['name'] }}</td>
                    @foreach ($columns as $key => $label)
                        <td class="num">{{ $money($employee['amounts'][$key]) }}</td>
                    @endforeach
                    <td class="num">{{ $money($employee['total']) }}</td>
                </tr>
            @endforeach
            </tbody>
            <tfoot>
            <tr class="subtotal">
                <td></td>
                <td>Total</td>
                @foreach ($columns as $key => $label)
                    <td class="num">{{ $money($statement['column_totals'][$key]) }}</td>
                @endforeach
                <td class="num">{{ $money($statement['salary_total']) }}</td>
            </tr>
            </tfoot>
        </table>
    @endif

    <div class="section-heading">Expenses</div>

    @if ($statement['expenses'] === [])
        <p class="empty">No expenses for this month.</p>
    @else
        <table class="grid">
            <tbody>
            @foreach ($statement['expenses'] as $index => $expense)
                <tr>
                    <td class="row-number">{{ $index + 1 }}</td>
                    <td>{{ $expense['description'] }}</td>
                    <td class="num">{{ $money($expense['amount']) }}</td>
                </tr>
            @endforeach
            </tbody>
            <tfoot>
            <tr class="subtotal">
                <td></td>
                <td>Total</td>
                <td class="num">{{ $money($statement['expense_total']) }}</td>
            </tr>
            </tfoot>
        </table>
    @endif

    @if ($statement['credits'] !== [])
        <div class="section-heading">Credits</div>

        <table class="grid">
            <tbody>
            @foreach ($statement['credits'] as $index => $credit)
                <tr class="credit">
                    <td class="row-number">{{ $index + 1 }}</td>
                    <td>{{ $credit['description'] }}</td>
                    <td class="num">{{ $money($credit['amount']) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif

    <table class="totals">
        <tr class="line">
            <td>Employees</td><td class="num">{{ $money($statement['salary_total']) }}</td>
        </tr>
        <tr class="line">
            <td>Expenses</td><td class="num">{{ $money($statement['expense_total']) }}</td>
        </tr>
        @if ($statement['credits'] !== [])
            <tr class="line">
                <td>Credits</td><td class="num">{{ $money($statement['credit_total']) }}</td>
            </tr>
        @endif
        <tr class="line grand">
            <td>Total to bill</td><td class="num">{{ $money($statement['subtotal']) }}</td>
        </tr>

        @if ($statement['client_total'] !== null)
            <tr class="line quoted">
                <td>At {{ number_format((float) $run->exchange_rate, 2) }} per {{ $run->currency }}</td>
                <td class="num">{{ $run->currency }} {{ $money($statement['client_total']) }}</td>
            </tr>
        @else
            <tr class="line">
                <td class="empty" colspan="2">Set a rate on the bill to see the {{ $run->currency }} figure.</td>
            </tr>
        @endif
    </table>
@endsection
