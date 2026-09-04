{{--
    Certificate of experience — the letter a next employer asks for.

    Shares the income certificate's sheet deliberately: same letterhead, same type sizes, same table. Two
    company letters that look like they came from two companies is the thing a recipient notices first.
    No flexbox and no grid, so Chrome and Dompdf render it identically and there is no `partials/dompdf-*`
    override to keep in step.

    Everything arrives resolved from `App\Modules\Employees\Services\ExperienceLetter`.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Certificate of Experience</title>
    <style>
        /* Zero, so the green footer bar reaches the paper edge — the inset lives on `.sheet`, exactly
           as the payslip arranges it. Everything else about how a letter looks is shared:
           `pdfs/partials/letterhead-styles`. */
        @page { margin: 0; }
    </style>

    {{-- The payslip's letterhead and its seal, shared by both letters. --}}
    @include('pdfs.partials.letterhead-styles')

    {{--
        This letter says less than the income certificate, so it has more page to fill: the same rhythm left
        five centimetres of nothing above the signature where the certificate left two and a half. The extra
        air goes here rather than in the shared sheet, which the certificate cannot afford.

        **Before the Dompdf block below, deliberately.** Both are plain element selectors, so the last one
        wins — which has to be Dompdf's, or this would undo the tightening that keeps it on one page.
    --}}
    <style>
        p { margin: 0 0 13pt; }
        h1.to-whom { margin: 24pt 0 19pt; }
        .subject { margin-bottom: 12pt; }
        table.details, table.roles { margin: 6pt 0 14pt; }
        table.details th, table.details td,
        table.roles th, table.roles td { padding: 4pt 7pt; }
    </style>

    {{-- Dompdf renders taller than Chrome and these letters are sized to one page, so it gets its own
         spacing. Same convention, same reason as `dompdf-payslip`. --}}
    @if (($pdfEngine ?? null) === 'dompdf')
        @include('pdfs.partials.dompdf-letter')
    @endif
</head>
<body>
<div class="sheet">

@include('pdfs.partials.letterhead', ['company' => $company, 'title' => 'Certificate of Experience'])

@php
    /*
     * The prose is assembled here, not with inline `@if`s: Blade does not compile a directive that follows
     * a word character, so `department@endif` mid-sentence stays literal while its opening `@if` compiles —
     * an unbalanced block and a PHP syntax error at render time.
     */
    $name = $employee->fullName();
    $tense = $has_left ? 'was employed' : 'has been employed';
    $period = $employee->date_of_joining?->format('d F Y').' to '
        .($has_left ? $left_on?->format('d F Y') : 'the date of this letter');
    $purposeClause = $purpose ? ' for '.rtrim($purpose, '.') : '';
    $departmentClause = $employee->department ? ' in the '.$employee->department.' department' : '';
@endphp

<table class="meta">
    <tr>
        <td>Date: {{ $issued_on->format('d F Y') }}</td>
        <td class="right">Ref: {{ $reference }}</td>
    </tr>
</table>

<h1 class="to-whom">TO WHOM IT MAY CONCERN</h1>

<div class="subject">
    SUBJECT: CERTIFICATE OF EXPERIENCE — {{ strtoupper($name) }}
</div>

<p>
    This is to certify that <strong>{{ $name }}</strong> {{ $tense }} with
    {{ $company['legal_name'] }} from {{ $period }}, most recently in the capacity of
    <strong>{{ $final_designation }}</strong>{{ $departmentClause }}.
</p>

<table class="details">
    <tr><th>Employee ID</th><td>{{ $employee->employee_id }}</td></tr>
    <tr><th>Date of Joining</th><td>{{ $employee->date_of_joining?->format('d F Y') }}</td></tr>
    @if ($has_left)
        <tr><th>Last Working Day</th><td>{{ $left_on?->format('d F Y') }}</td></tr>
    @endif
    <tr><th>Total Length of Service</th><td>{{ $service_length }}</td></tr>
    <tr><th>{{ $has_left ? 'Designation at Separation' : 'Current Designation' }}</th><td>{{ $final_designation }}</td></tr>
    @if ($monthly_gross !== null)
        {{-- Printed only when it was explicitly asked for. What somebody earned here is their business to
             disclose at their next negotiation. --}}
        <tr>
            <th>{{ $has_left ? 'Last Drawn Gross Salary' : 'Gross Monthly Salary' }}</th>
            <td>PKR {{ number_format($monthly_gross, 0) }} per month</td>
        </tr>
    @endif
</table>

@if (count($roles) > 1)
    <p>The positions held during this period were:</p>

    <table class="roles">
        <thead>
            <tr>
                <th style="width: 26%">From</th>
                <th>Designation</th>
                <th style="width: 26%">Department</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($roles as $role)
                <tr>
                    <td>{{ $role['from']->format('d F Y') }}</td>
                    <td>{{ $role['designation'] }}</td>
                    <td>{{ $role['department'] ?: '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

@if ($duties)
    <p>The principal duties included {{ rtrim($duties, '.') }}.</p>
@endif

<p>
    During the period of employment, conduct and performance were found to be {{ $conduct }}.
</p>

@if ($has_left)
    <p>
        We wish {{ $name }} every success in future endeavours. This certificate is issued upon the
        employee's request{{ $purposeClause }}.
    </p>
@else
    <p>
        This certificate is issued upon the employee's request{{ $purposeClause }} and confirms employment
        as at the date above.
    </p>
@endif

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
