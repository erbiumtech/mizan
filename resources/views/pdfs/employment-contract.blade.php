{{-- An employment contract — docs/saas-launch-plan.md §14.5. Letterhead, the terms, the QR, and the acceptance block. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Employment contract {{ $contract->number }}</title>
    <style>
        body { font-family: "DejaVu Sans", Arial, sans-serif; font-size: 11pt; color: #111; margin: 20mm; }
        h1 { font-size: 16pt; margin: 0 0 4mm; }
        .muted { color: #555; font-size: 9pt; }
        table.terms { border-collapse: collapse; width: 100%; margin: 6mm 0; }
        table.terms th, table.terms td { text-align: left; padding: 2mm 3mm; border-bottom: 1px solid #ddd; vertical-align: top; }
        table.terms th { width: 40%; color: #555; font-weight: normal; }
        .sign { margin-top: 14mm; width: 100%; }
        .sign td { width: 50%; padding-top: 10mm; vertical-align: top; }
        .qr { float: right; width: 28mm; }
    </style>
</head>
<body>
    @if(!empty($verification_qr))<img class="qr" src="{{ $verification_qr }}" alt="Verification code">@endif
    <h1>{{ $company['legal_name'] ?? '' }}</h1>
    <p class="muted">{{ $company['address'] ?? '' }}</p>
    <h2>Employment contract {{ $contract->number }}</h2>
    <p>This contract is made between <strong>{{ $company['legal_name'] ?? 'the company' }}</strong> and <strong>{{ $employee?->fullName() }}</strong> ({{ $employee?->employee_id }}).</p>
    <table class="terms">
        <tr><th>Type</th><td>{{ $contract->typeLabel() }}</td></tr>
        <tr><th>Designation</th><td>{{ $employee?->designation ?: '—' }}</td></tr>
        <tr><th>Starts on</th><td>{{ $contract->starts_on->format('d F Y') }}</td></tr>
        <tr><th>Ends on</th><td>{{ $contract->ends_on?->format('d F Y') ?? 'Open-ended' }}</td></tr>
        <tr><th>Monthly gross</th><td>{{ number_format((float) $contract->gross, 2) }}</td></tr>
        <tr><th>Probation</th><td>{{ $contract->probation_months ? $contract->probation_months.' month(s)' : 'None' }}</td></tr>
    </table>
    @if($contract->notes)<p>{{ $contract->notes }}</p>@endif
    @if($contract->isAccepted())
        <p class="muted">Accepted by the employee in the system on {{ $contract->accepted_at->format('d F Y H:i') }}@if($contract->accepted_ip) from {{ $contract->accepted_ip }}@endif.@if($contract->witnesses) Witnesses: {{ implode(', ', $contract->witnesses) }}.@endif</p>
    @endif
    <table class="sign">
        <tr>
            <td>______________________________<br>{{ $signatory['name'] ?? 'For the company' }}<br><span class="muted">{{ $signatory['title'] ?? '' }}</span></td>
            <td>______________________________<br>{{ $employee?->fullName() }}<br><span class="muted">Employee</span></td>
        </tr>
    </table>
</body>
</html>
