{{--
    Offer of employment — the candidate's copy of what was agreed.

    Shares the experience letter's sheet: same letterhead, same type sizes, same table. No flexbox and no
    grid, so Chrome and Dompdf render it identically. Everything arrives resolved from
    `App\Modules\Recruitment\Services\OfferLetter`.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Offer of Employment</title>
    <style>
        @page { margin: 0; }
    </style>

    @include('pdfs.partials.letterhead-styles')

    <style>
        p { margin: 0 0 12pt; }
        h1.to-whom { margin: 22pt 0 16pt; }
        .subject { margin-bottom: 12pt; }
        table.details, table.roles { margin: 6pt 0 14pt; }
        table.details th, table.details td,
        table.roles th, table.roles td { padding: 4pt 7pt; }
        .accept { margin-top: 18pt; }
    </style>

    @if (($pdfEngine ?? null) === 'dompdf')
        @include('pdfs.partials.dompdf-letter')
    @endif
</head>
<body>
<div class="sheet">

@include('pdfs.partials.letterhead', ['company' => $company, 'title' => 'Offer of Employment'])

@php
    $name = $applicant->name;
    $departmentClause = $department ? ' in the '.$department.' department' : '';
    $typeClause = $employment_type ? ' on a '.strtolower($employment_type).' basis' : '';
@endphp

<table class="meta">
    <tr>
        <td>Date: {{ $issued_on->format('d F Y') }}</td>
        <td class="right">Ref: {{ $reference }}</td>
    </tr>
</table>

<p>
    {{ $name }}<br>
    @if ($applicant->email){{ $applicant->email }}<br>@endif
</p>

<div class="subject">SUBJECT: OFFER OF EMPLOYMENT — {{ strtoupper($position) }}</div>

<p>Dear {{ $name }},</p>

<p>
    Further to your application and interviews, {{ $company['legal_name'] }} is pleased to offer you the
    position of <strong>{{ $position }}</strong>{{ $departmentClause }}{{ $typeClause }}, on the terms below.
</p>

<table class="details">
    <tr><th>Position</th><td>{{ $position }}</td></tr>
    @if ($department)<tr><th>Department</th><td>{{ $department }}</td></tr>@endif
    @if ($employment_type)<tr><th>Employment Type</th><td>{{ $employment_type }}</td></tr>@endif
    <tr><th>Date of Joining</th><td>{{ $offer->joining_date->format('d F Y') }}</td></tr>
    <tr><th>Basic Salary</th><td>PKR {{ number_format($basic, 0) }} per month</td></tr>
    @foreach ($components as $component)
        <tr><th>{{ $component['label'] }}</th><td>PKR {{ number_format($component['amount'], 0) }} per month</td></tr>
    @endforeach
    <tr><th>Gross Monthly Salary</th><td><strong>PKR {{ number_format($gross, 0) }}</strong> per month</td></tr>
</table>

<p>
    Salary is paid monthly in arrears by bank transfer, less income tax deducted at source under the
    Income Tax Ordinance and any statutory contributions that apply. Your employment is governed by the
    company's policies as issued from time to time, and the appointment letter you receive on joining
    will set out the remaining terms.
</p>

<p>
    This offer is valid until <strong>{{ $valid_until->format('d F Y') }}</strong>. Please confirm your
    acceptance by signing below and returning a copy, or by replying to the email this letter came with.
</p>

<p>We look forward to welcoming you.</p>

<p class="sincerely">Sincerely,</p>

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

<table class="details accept">
    <tr><th style="width: 40%">Accepted by</th><td>&nbsp;</td></tr>
    <tr><th>Signature and date</th><td>&nbsp;</td></tr>
</table>

</div>

@include('pdfs.partials.letterhead-footer', ['company' => $company])

</body>
</html>
