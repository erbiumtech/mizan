{{--
    Certificate of employment, profession and source of income.

    **Written for both engines without an override partial**, unlike the payslip: not one flexbox row, not
    one grid, no absolute positioning — a letter is paragraphs and one table of facts, and both Chrome and
    Dompdf render that identically. `pdfs/partials/dompdf-*` exists because the payslip's design needed it;
    this one earns nothing by having a second sheet to keep in step.

    Everything is resolved in `App\Modules\Employees\Services\IncomeCertificate` and arrives as strings.
    Deciding which allowances count as recurring income is not a thing a template should be doing.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Certificate of Employment and Source of Income</title>
    <style>
        /* Zero, so the green footer bar reaches the paper edge — the inset lives on `.sheet`, exactly
           as the payslip arranges it. Everything else about how a letter looks is shared:
           `pdfs/partials/letterhead-styles`. */
        @page { margin: 0; }
    </style>

    {{-- The payslip's letterhead and its seal, shared by both letters. --}}
    @include('pdfs.partials.letterhead-styles')

    {{-- Dompdf renders taller than Chrome and these letters are sized to one page, so it gets its own
         spacing. Same convention, same reason as `dompdf-payslip`. --}}
    @if (($pdfEngine ?? null) === 'dompdf')
        @include('pdfs.partials.dompdf-letter')
    @endif
</head>
<body>
<div class="sheet">

@include('pdfs.partials.letterhead', ['company' => $company, 'title' => 'Certificate of Income'])

<table class="meta">
    <tr>
        <td>Date: {{ $issued_on->format('d F Y') }}</td>
        <td class="right">Ref: {{ $reference }}</td>
    </tr>
</table>

<h1 class="to-whom">TO WHOM IT MAY CONCERN</h1>

<div class="subject">
    SUBJECT: CERTIFICATE OF EMPLOYMENT, PROFESSION AND SOURCE OF INCOME
    {{-- `fullName()` rather than the two fields inline: the linked user's name first, the record's own as
         the fallback for staff with no login. It is what the whole panel labels this person by, and a
         certificate that disagreed with the screen would be the one nobody trusts. --}}
    — {{ strtoupper($employee->fullName()) }}
</div>

@php
    /*
     * The identity clause is assembled here rather than with inline `@if`s, and that is not a style
     * preference: Blade does not compile a directive that follows a word character, so `employee@if (...)`
     * mid-sentence is left as literal text while its `@endif` compiles — an unbalanced block and a PHP
     * syntax error at render time. Prose with optional middles is safer built as a string.
     */
    $identity = array_filter([
        'holder of CNIC No. '.$employee->nic,
        $father_name ? 'son/daughter of '.$father_name : null,
        $residence ? 'residing at '.$residence : null,
    ]);

    $purposeClause = $purpose ? ' for '.rtrim($purpose, '.') : '';
@endphp

<p>
    This is to certify that <strong>{{ $employee->fullName() }}</strong>,
    {{ implode(', ', $identity) }}, is a bona fide employee of {{ $company['legal_name'] }}.
</p>

<p>The details of the employment are as follows:</p>

<table class="details">
    <tr><th>Employee ID</th><td>{{ $employee->employee_id }}</td></tr>
    <tr><th>Designation</th><td>{{ $employee->designation }}</td></tr>
    @if ($employee->department)
        <tr><th>Department</th><td>{{ $employee->department }}</td></tr>
    @endif
    <tr><th>Date of Joining</th><td>{{ $employee->date_of_joining?->format('d F Y') }}</td></tr>
    <tr><th>Employment Status</th><td>{{ $employment_status }}</td></tr>
    <tr>
        <th>Gross Monthly Salary</th>
        <td>PKR {{ number_format($monthly_gross, 0) }} ({{ $monthly_gross_words }} only)</td>
    </tr>
    <tr>
        <th>Annual Gross Salary</th>
        <td>PKR {{ number_format($annual_gross, 0) }} ({{ $annual_gross_words }} only)</td>
    </tr>
    {{-- The caveat sits with the figures it qualifies rather than in a footnote at the foot of the page.
         It reads better here, and it was the block that tipped the letter onto a second page under Dompdf:
         page two carried nothing but this line and the green bar. --}}
    <tr>
        <td colspan="2" class="note">
            Both figures are the recurring monthly package in force on the date of issue. Bonuses and
            overtime vary by month and are excluded.
        </td>
    </tr>
    @if ($employee->bank?->bank_name || $employee->bank_account_no)
        <tr>
            <th>Mode of Payment</th>
            {{-- Composed rather than laid out across lines: Blade keeps the newline and the indentation, so
                 the markup version printed "MCB Bank Limited , Account No." with a space before the comma.
                 Last four digits only — the letter proves the salary arrives through a bank, and a full
                 account number on a document that will be photocopied proves nothing more. --}}
            <td>{{ implode(', ', array_filter([
                'Bank transfer'.($employee->bank?->bank_name ? ' to '.$employee->bank->bank_name : ''),
                $employee->bank_account_no ? 'Account No. ending '.substr($employee->bank_account_no, -4) : null,
            ])) }}</td>
        </tr>
    @endif
</table>

@if ($duties)
    <p>The principal duties include {{ rtrim($duties, '.') }}.</p>
@endif

<p>
    We further certify that the above-mentioned salary is the employee's source of income from this Company,
    is paid through regular banking channels, and that all applicable income tax deductions are made at
    source and deposited with the Federal Board of Revenue in accordance with the Income Tax Ordinance,
    2001.
</p>

<p>
    This certificate is issued upon the request of the employee{{ $purposeClause }} and does not constitute
    a guarantee of any financial obligation on the part of the Company.
</p>

<p>Should you require any verification, please contact the undersigned.</p>

{{-- "Sincerely," stays in the flow with the prose it closes; the signature block below is pinned to the
     foot of the page, so gluing the two together would drag the sign-off down with it. --}}
<p class="sincerely">Sincerely,</p>

<div class="sign">

    {{-- The seal: the same employer signature the payslip carries, so all three documents are signed the
         same way. A letter with the seal on it is one a recipient accepts without chasing a wet signature;
         guarded on the file existing so an installation without the asset gets a signature line instead of
         a broken image. --}}
    @if (file_exists(public_path('signatures/employer_signature1.png')))
        <img src="{{ public_path('signatures/employer_signature1.png') }}" class="seal-image" alt="">
    @endif

    <div class="rule"></div>
    <div class="who">{{ $signatory['name'] }}</div>
    <div>{{ $signatory['title'] }}</div>
    <div>{{ $company['legal_name'] }}</div>
    {{-- No contact line here: the green bar below carries the phone, the email and the website, and this
         repeated all three a centimetre above it. It was also the last line on the page, and under Dompdf
         it was the one that spilled onto a second — a duplicate costing a whole sheet of paper. --}}
</div>

</div>

@include('pdfs.partials.letterhead-footer', ['company' => $company])

</body>
</html>
