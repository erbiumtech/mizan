{{--
    Certificate of income tax deducted from salary, for one employee and one tax year.

    Written for both engines without an override partial, like the income certificate
    and unlike the payslip: paragraphs and two tables of facts, no flexbox, no grid.
    Everything is resolved in App\Modules\Payroll\Services\WithholdingCertificate —
    which months count and what "taxable" means are not template decisions.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Certificate of Tax Deducted from Salary</title>
    <style>
        @page { margin: 0; }
        table.withholding { width: 100%; border-collapse: collapse; margin: 12px 0; }
        table.withholding th { text-align: left; font-size: 9px; text-transform: uppercase; color: #6b7280; padding: 4px 8px; border-bottom: 1px solid #9ca3af; }
        table.withholding td { padding: 4px 8px; border-bottom: 1px solid #e5e7eb; font-size: 11px; }
        table.withholding .num { text-align: right; white-space: nowrap; }
        table.withholding tr.total td { font-weight: bold; border-top: 1px solid #9ca3af; border-bottom: none; }
    </style>

    @include('pdfs.partials.letterhead-styles')

    @if (($pdfEngine ?? null) === 'dompdf')
        @include('pdfs.partials.dompdf-letter')
    @endif
</head>
<body>
<div class="sheet">

@include('pdfs.partials.letterhead', ['company' => $company, 'title' => 'Certificate of Tax Deducted'])

<table class="meta">
    <tr>
        <td>Date: {{ $issued_on->format('d F Y') }}</td>
        <td class="right">Ref: {{ $reference }}</td>
    </tr>
</table>

<h1 class="to-whom">CERTIFICATE OF TAX DEDUCTED FROM SALARY</h1>

<p>
    This is to certify that income tax detailed below was deducted at source under section 149 of the
    Income Tax Ordinance, 2001 from salary paid by <strong>{{ $company['name'] ?? '' }}</strong> to
    <strong>{{ $employee->user?->name ?: $employee->name }}</strong>
    (CNIC {{ $employee->nic }}@if($employee->employee_id), employee no. {{ $employee->employee_id }}@endif)
    during Tax Year <strong>{{ $tax_year }}</strong> ({{ $fiscal_year }}).
</p>

<table class="withholding">
    <thead>
        <tr>
            <th>Month</th>
            <th class="num">Taxable salary (PKR)</th>
            <th class="num">Tax deducted (PKR)</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($rows as $row)
            <tr>
                <td>{{ $row['month'] }}</td>
                <td class="num">{{ number_format($row['taxable'], 2) }}</td>
                <td class="num">{{ number_format($row['tax'], 2) }}</td>
            </tr>
        @endforeach
        <tr class="total">
            <td>Total for the year</td>
            <td class="num">{{ number_format($taxable_total, 2) }}</td>
            <td class="num">{{ number_format($tax_total, 2) }}</td>
        </tr>
    </tbody>
</table>

<p>
    The amounts deducted were deposited with the Federal Board of Revenue through the company's
    monthly withholding statements under section 165, which carry the deposit references. Only the
    months in which tax was deducted are listed, matching those statements.
</p>

<p>
    This certificate is issued at the employee's request for their own income tax return, from the
    payroll records as they stand on the date above.
</p>

<p class="sincerely">Sincerely,</p>

<div class="sign">
    {{-- The same seal-or-signature-line arrangement as the income certificate, so
         all the letters this company issues are signed the same way. --}}
    @if ($seal = \App\Support\CompanyLetterhead::signatureDataUri())
        <img src="{{ $seal }}" class="seal-image" alt="">
    @elseif (file_exists(public_path('signatures/employer_signature1.png')))
        <img src="{{ public_path('signatures/employer_signature1.png') }}" class="seal-image" alt="">
    @endif

    <div class="rule"></div>
    <div class="who">{{ $signatory['name'] }}</div>
    <div>{{ $signatory['title'] }}</div>
    <div>{{ $company['legal_name'] }}</div>
</div>

</div>

@include('pdfs.partials.letterhead-footer', ['company' => $company])

</body>
</html>
