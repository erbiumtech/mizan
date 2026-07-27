@php
    use Illuminate\Support\Facades\Storage;

    $img = function (?string $path): ?string {
        if (! $path || ! Storage::disk('public')->exists($path)) {
            return null;
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION)) ?: 'png';

        return 'data:image/'.$ext.';base64,'.base64_encode(Storage::disk('public')->get($path));
    };

    $rows = [
        'Employee ID' => $employee->employee_id,
        'Name' => $employee->user?->name,
        'Email' => $employee->user?->email,
        'Gender' => $employee->gender,
        'NIC' => $employee->nic,
        'Date of Joining' => optional($employee->date_of_joining)->format('d-m-Y'),
        'Phone' => $employee->phone,
        'Secondary Phone' => $employee->secondary_phone,
        'Address 1' => $employee->address_line_1,
        'Address 2' => $employee->address_line_2,
        'Designation' => $employee->designation,
        'Department' => $employee->department,
        'Status' => $employee->is_active ? 'Active' : 'Inactive',
        'Manager' => $employee->manager?->display_label,
        'Bank' => $employee->bank?->bank_name,
        'Bank A/C No' => $employee->bank_account_no,
        'IBAN' => $employee->iban_no,
    ];
    $front = $img($employee->nic_front);
    $back = $img($employee->nic_back);
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { color: #111827; font-size: 12px; margin: 32px; }
        h1 { font-size: 20px; margin: 0 0 4px; }
        .sub { color: #6b7280; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 7px 8px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
        td.k { width: 35%; color: #6b7280; font-weight: bold; }
        .nic { margin-top: 24px; }
        .nic h2 { font-size: 14px; margin-bottom: 8px; }
        .nic-imgs { display: flex; gap: 16px; }
        .nic-imgs div { flex: 1; }
        .nic-imgs img { width: 100%; border: 1px solid #e5e7eb; border-radius: 6px; }
        .cap { color: #6b7280; font-size: 11px; margin-bottom: 4px; }
    </style>

    @if (($pdfEngine ?? null) === 'dompdf')
        @include('pdfs.partials.dompdf-employee')
    @endif
</head>
<body>
    <h1>Employee Information</h1>
    <div class="sub">{{ $employee->display_label }}</div>

    <table>
        @foreach ($rows as $label => $value)
            <tr>
                <td class="k">{{ $label }}</td>
                <td>{{ ($value === null || $value === '') ? '—' : $value }}</td>
            </tr>
        @endforeach
    </table>

    @if ($front || $back)
        <div class="nic">
            <h2>NIC Images</h2>
            <div class="nic-imgs">
                <div>
                    <div class="cap">Front</div>
                    @if ($front)<img src="{{ $front }}">@else <span>—</span> @endif
                </div>
                <div>
                    <div class="cap">Back</div>
                    @if ($back)<img src="{{ $back }}">@else <span>—</span> @endif
                </div>
            </div>
        </div>
    @endif
</body>
</html>
