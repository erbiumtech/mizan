@extends('reports.layout')

@section('title', 'Tax Summary')

@section('content')
    @php($money = fn ($amount) => number_format((float) $amount, 2))

    <h1>Tax Summary</h1>
    <p class="meta">
        Salary withholding, section 149/4 &middot;
        {{ $report['month'] ? $report['month'].' '.$report['fiscal_year'] : $report['fiscal_year'] }} &middot;
        {{ $money($report['tax_total']) }} withheld from {{ $money($report['taxable_total']) }} of earnings
    </p>

    @unless($pdf)
        <form class="toolbar" method="GET">
            <div>
                <label for="month">Month</label>
                <input type="text" id="month" name="month" value="{{ $report['month'] }}" placeholder="The whole year">
            </div>
            <input type="hidden" name="fiscal_year_id" value="{{ $report['fiscal_year_id'] }}">
            <button class="btn btn-primary" type="submit">Run</button>
            <a class="btn btn-secondary" href="{{ request()->fullUrlWithQuery(['format' => 'pdf']) }}">Download PDF</a>
        </form>
    @endunless

    <table>
        <thead>
        <tr><th>Month</th><th class="num">Employees</th><th class="num">Taxable</th><th class="num">Tax</th></tr>
        </thead>
        <tbody>
        @forelse($report['months'] as $row)
            <tr>
                <td>{{ $row['month'] }}</td>
                <td class="num">{{ $row['employees'] }}</td>
                <td class="num">{{ $money($row['taxable']) }}</td>
                <td class="num">{{ $money($row['tax']) }}</td>
            </tr>
        @empty
            <tr><td colspan="4" class="meta">No tax withheld in this period.</td></tr>
        @endforelse
        <tr class="grand">
            <td colspan="2">Total</td>
            <td class="num">{{ $money($report['taxable_total']) }}</td>
            <td class="num">{{ $money($report['tax_total']) }}</td>
        </tr>
        </tbody>
    </table>

    <table>
        <thead>
        <tr><th>Employee</th><th>CNIC</th><th class="num">Months</th><th class="num">Taxable</th><th class="num">Tax</th></tr>
        </thead>
        <tbody>
        @forelse($report['employees'] as $row)
            <tr>
                <td>{{ $row['name'] }}</td>
                <td>{{ $row['nic'] }}</td>
                <td class="num">{{ $row['months'] }}</td>
                <td class="num">{{ $money($row['taxable']) }}</td>
                <td class="num">{{ $money($row['tax']) }}</td>
            </tr>
        @empty
            <tr><td colspan="5" class="meta">Nothing to report.</td></tr>
        @endforelse
        </tbody>
    </table>
@endsection
