{{--
    Appointment letter — the terms, for the person who has just joined.

    Shares the experience letter's sheet. Everything arrives resolved from
    `App\Modules\Employees\Services\AppointmentLetter`.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Appointment Letter</title>
    <style>
        @page { margin: 0; }
    </style>

    @include('pdfs.partials.letterhead-styles')

    <style>
        p { margin: 0 0 12pt; }
        .subject { margin-bottom: 12pt; }
        table.details { margin: 6pt 0 14pt; }
        table.details th, table.details td { padding: 4pt 7pt; }
        ol { margin: 0 0 12pt 18pt; padding: 0; }
        ol li { margin-bottom: 6pt; }
    </style>

    @if (($pdfEngine ?? null) === 'dompdf')
        @include('pdfs.partials.dompdf-letter')
    @endif
</head>
<body>
<div class="sheet">

@include('pdfs.partials.letterhead', ['company' => $company, 'title' => 'Appointment Letter'])

@php
    $name = $employee->fullName();
    $departmentClause = $employee->department ? ' in the '.$employee->department.' department' : '';
@endphp

<table class="meta">
    <tr>
        <td>Date: {{ $issued_on->format('d F Y') }}</td>
        <td class="right">Ref: {{ $reference }}</td>
    </tr>
</table>

<p>{{ $name }}<br>Employee ID: {{ $employee->employee_id }}</p>

<div class="subject">SUBJECT: LETTER OF APPOINTMENT — {{ strtoupper((string) $employee->designation) }}</div>

<p>Dear {{ $name }},</p>

<p>
    We are pleased to confirm your appointment with {{ $company['legal_name'] }} as
    <strong>{{ $employee->designation }}</strong>{{ $departmentClause }}, with effect from
    <strong>{{ $joining_on->format('d F Y') }}</strong>, on the following terms.
</p>

<table class="details">
    <tr><th>Designation</th><td>{{ $employee->designation }}</td></tr>
    @if ($employee->department)<tr><th>Department</th><td>{{ $employee->department }}</td></tr>@endif
    @if ($employee->employment_type)<tr><th>Employment Type</th><td>{{ ucfirst((string) $employee->employment_type) }}</td></tr>@endif
    <tr><th>Date of Joining</th><td>{{ $joining_on->format('d F Y') }}</td></tr>
    @if ($reports_to)<tr><th>Reports To</th><td>{{ $reports_to }}</td></tr>@endif
    @if ($monthly_gross !== null)
        <tr><th>Gross Monthly Salary</th><td><strong>{{ \App\Support\CompanyCurrency::code() }} {{ number_format($monthly_gross, 0) }}</strong> per month</td></tr>
    @endif
</table>

<ol>
    @if ($probation_months > 0)
        <li>
            The first {{ $probation_months }} {{ $probation_months === 1 ? 'month' : 'months' }} of service are a
            probationary period, at the end of which the appointment is confirmed in writing.
        </li>
    @endif
    <li>
        Salary is paid monthly in arrears by bank transfer, less income tax deducted at source under the
        Income Tax Ordinance and any statutory contributions that apply.
    </li>
    <li>
        Working hours, leave and conduct are governed by the company's policies as issued from time to time,
        which form part of these terms.
    </li>
    <li>
        Information about the company, its clients and its work is confidential during and after your
        employment.
    </li>
</ol>

<p>
    Please sign and return a copy of this letter as your acceptance of these terms. We welcome you to the
    company and wish you every success in your role.
</p>

<p class="sincerely">Sincerely,</p>

@include('pdfs.partials.verification-qr')

<div class="sign">
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
