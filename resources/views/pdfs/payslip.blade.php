<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <style>
        @page {
            margin: 0 !important;
            size: A4;
        }

        html,
        body {
            width: 210mm;
            height: 297mm;
            font-family: 'Arial', sans-serif;
            margin: 0 !important;
            padding: 0 !important;
            color: #333;
            font-size: 13px;
            background: white;
            -webkit-print-color-adjust: exact;
        }

        .payslip-wrapper {
            position: relative;
            width: 210mm;
            height: 297mm;
            box-sizing: border-box;
        }

        .container {
            padding: 50px 50px 140px 50px;
            box-sizing: border-box;
            position: relative;
            width: 100%;
        }

        /* Header Styles */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            padding-bottom: 20px;
        }

        .logo-section {
            display: flex;
            align-items: flex-end;
            gap: 12px;
        }

        .bars {
            display: flex;
            align-items: flex-end;
            gap: 4px;
            margin-bottom: 4px;
        }

        .bar {
            background-color: #6cbf4a;
            width: 9px;
        }

        .bar-1 {
            height: 18px;
        }

        .bar-2 {
            height: 26px;
        }

        .bar-3 {
            height: 36px;
            background-color: #388e3c;
        }

        .company-text {
            line-height: 1.1;
        }

        .erbium {
            font-size: 28px;
            font-weight: bolder;
            color: #111;
        }

        .tech {
            font-size: 28px;
            font-weight: bolder;
            color: #6cbf4a;
        }

        .smc {
            font-size: 11px;
            color: #555;
            letter-spacing: 0.5px;
            font-weight: bold;
        }

        .header-right {
            text-align: right;
            line-height: 1.5;
        }

        .address-top {
            font-size: 13px;
            color: #333;
            margin-bottom: 4px;
        }

        .title {
            font-size: 20px;
            font-weight: bold;
            color: #388e3c;
        }

        .dashed-divider {
            border-bottom: 2.5px dashed #6cbf4a;
            margin-bottom: 20px;
        }

        /* Employee Info */
        .info-grid {
            display: flex;
            justify-content: space-between;
            margin-bottom: 20px;
            font-size: 13px;
        }

        .info-col {
            width: 48%;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
        }

        .label {
            color: #555;
            width: 40%;
        }

        .value {
            font-weight: bold;
            text-align: right;
            width: 60%;
        }

        /* Attendance Box */
        .attendance-box {
            background-color: #f5f4ef;
            padding: 15px 20px;
            display: flex;
            justify-content: space-between;
            border-radius: 6px;
            margin-bottom: 20px;
            border: 1px solid #eee;
        }

        .att-col {
            width: 45%;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .att-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .att-box {
            background-color: white;
            border: 1px solid #ddd;
            padding: 4px 12px;
            border-radius: 4px;
            min-width: 30px;
            text-align: center;
            font-weight: bold;
            font-size: 12px;
        }

        /* Main Table */
        .main-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 0;
        }

        .main-table th {
            background-color: #3a8a3f;
            color: white;
            padding: 12px;
            text-align: left;
            font-size: 13px;
            width: 50%;
            border: 1px solid #3a8a3f;
        }

        .main-table td {
            padding: 0;
            border: 1px solid #eee;
            vertical-align: top;
        }

        .line-item {
            display: flex;
            justify-content: space-between;
            padding: 14px 12px;
            border-bottom: 1px solid #f5f5f5;
            font-size: 12px;
        }

        .totals-row {
            display: flex;
            justify-content: space-between;
            padding: 12px;
            background-color: #f1f8f1;
            font-weight: bold;
            font-size: 12px;
            color: #2e7d32;
        }

        /* Net Salary Box */
        .net-salary-box {
            background-color: #3a8a3f;
            color: white;
            padding: 16px 12px;
            display: flex;
            justify-content: space-between;
            font-size: 15px;
            font-weight: bold;
            margin-bottom: 25px;
        }

        /* ==================== SIGNATURES ==================== */
        .signatures {
            display: flex;
            justify-content: space-between;
            gap: 60px;
            margin-top: 25px;
            margin-bottom: 10px;
        }

        .signature-block {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            margin-top: 10px;
            min-height: 160px;
        }

        .sig-image {
            max-width: 100px;
            max-height: 80px;
            width: auto;
            height: auto;
            object-fit: contain;
            margin-bottom: 6px;
            filter: contrast(1.1) saturate(1.05);
        }

        .sig-line {
            width: 100%;
            border-top: 2px solid #000;
            padding-top: 6px;
            text-align: center;
            font-size: 11px;
            font-weight: bold;
            color: #333;
        }

        /* Footer */
        .footer {
            position: absolute;
            bottom: 0;
            left: 0;
            width: 210mm;
            height: auto;
            background-color: #55a65a;
            color: white;
            padding: 14px 50px;
            display: flex;
            justify-content: space-between;
            font-size: 10px;
            line-height: 1.5;
            box-sizing: border-box;
        }

        .footer div {
            flex: 1;
        }

        .footer-right {
            text-align: right;
        }
    </style>
</head>

<body>
    <div class="payslip-wrapper">
        <div class="container">
            <!-- Header -->
            <div class="header">
                <div class="logo-section">
                    <div class="bars">
                        <div class="bar bar-1"></div>
                        <div class="bar bar-2"></div>
                        <div class="bar bar-3"></div>
                    </div>
                    <div class="company-text">
                        <span class="erbium">Erbium</span><span class="tech">Tech</span><br>
                        <span class="smc">SMC-PRIVATE LIMITED</span>
                    </div>
                </div>
                <div class="header-right">
                    <div class="address-top">350/A Khayaban-e-Zafar, Lahore</div>
                    <div class="title">
                        Pay Slip — {{ $data->month }} {{ $data->fiscalYear ? $data->fiscalYear->name : '' }}
                    </div>
                </div>
            </div>

            <div class="dashed-divider"></div>

            <!-- Employee Info -->
            <div class="info-grid">
                <div class="info-col">
                    <div class="info-row"><span class="label">Name</span><span
                            class="value">{{ $data->employee->user->name ?? '-' }}</span></div>
                    <div class="info-row"><span class="label">Employee ID</span><span
                            class="value">{{ $data->employee->employee_id ?? '-' }}</span></div>
                    <div class="info-row"><span class="label">Designation</span><span
                            class="value">{{ $data->employee->designation ?? '-' }}</span></div>
                    <div class="info-row"><span class="label">Department</span><span
                            class="value">{{ $data->employee->department ?? '-' }}</span></div>
                    <div class="info-row"><span class="label">Date of Joining</span><span
                            class="value">{{ !empty($data->employee->date_of_joining) ? \Carbon\Carbon::parse($data->employee->date_of_joining)->format('d-m-Y') : '-' }}</span>
                    </div>
                </div>
                <div class="info-col">
                    <div class="info-row"><span class="label">NIC</span><span
                            class="value">{{ $data->employee->nic ?? '-' }}</span></div>
                    <div class="info-row"><span class="label">Bank Name</span><span
                            class="value">{{ $data->employee->bank->bank_name ?? '-' }}</span></div>
                    <div class="info-row"><span class="label">Bank A/C No</span><span
                            class="value">{{ $data->employee->bank_account_no ?? '-' }}</span></div>
                    <div class="info-row"><span class="label">IBAN No.</span><span
                            class="value">{{ $data->employee->iban_no ?? '-' }}</span></div>
                </div>
            </div>

            <!-- Attendance -->
            <div class="attendance-box">
                <div class="att-col">
                    <div class="att-row"><span class="label">Total Working Days</span><span
                            class="att-box">{{ $data->total_working_days ?? 0 }}</span></div>
                    <div class="att-row"><span class="label">LOP Days</span><span
                            class="att-box">{{ $data->lop_days ?? 0 }}</span></div>
                </div>
                <div class="att-col">
                    <div class="att-row"><span class="label">Paid Days</span><span
                            class="att-box">{{ $data->paid_days ?? 0 }}</span></div>
                    <div class="att-row"><span class="label">Leaves Taken</span><span
                            class="att-box">{{ $data->leaves_taken ?? 0 }}</span></div>
                </div>
            </div>

            <!-- Salary Table -->
            <table class="main-table">
                <tr>
                    <th>Earnings</th>
                    <th>Deductions</th>
                </tr>
                <tr>
                    <td>
                        <div class="line-item"><span>Basic
                                Wage</span><span>{{ number_format($data->basic_wage, 2) }}</span></div>
                        <div class="line-item"><span>Medical
                                Allowances</span><span>{{ number_format($data->medical_allowance, 2) }}</span></div>
                        <div class="line-item"><span>Device
                                Allowances</span><span>{{ number_format($data->device_allowance, 2) }}</span></div>
                        <div class="line-item"><span>Petrol
                                Allowances</span><span>{{ number_format($data->petrol_allowance, 2) }}</span></div>
                        <div class="line-item"><span>Extra Work
                                Hours</span><span>{{ number_format($data->extra_work_hours, 2) }}</span></div>
                        <div class="line-item"><span>Bonus</span><span>{{ number_format($data->bonus, 2) }}</span>
                        </div>
                    </td>
                    <td>
                        <div class="line-item"><span>Withholding
                                Tax</span><span>{{ number_format($data->withholding_tax, 2) }}</span></div>
                        <div class="line-item">
                            <span>Advances</span><span>{{ number_format($data->advances, 2) }}</span>
                        </div>
                        <div class="line-item"><span>Meal
                                Deduction</span><span>{{ number_format($data->meal_deduction, 2) }}</span></div>
                        <div class="line-item"><span>ESI / Health
                                Insurance</span><span>{{ number_format($data->esi_health_insurance, 2) }}</span></div>
                    </td>
                </tr>
                <tr>
                    <td style="padding: 0;">
                        <div class="totals-row">
                            <span>Total Earnings</span>
                            <span>PKR {{ number_format($data->total_earnings, 2) }}</span>
                        </div>
                    </td>
                    <td style="padding: 0;">
                        <div class="totals-row">
                            <span>Total Deductions</span>
                            <span>PKR {{ number_format($data->total_deductions, 2) }}</span>
                        </div>
                    </td>
                </tr>
            </table>

            <div class="net-salary-box">
                <span>Net Salary</span>
                <span>PKR {{ number_format($data->net_salary, 2) }}</span>
            </div>

            <!-- Signatures -->
            <div class="signatures">
                <div class="signature-block">
                    <img src="{{ public_path('signatures/employer_signature1.png') }}" class="sig-image"
                        alt="Employer Signature">
                    <div class="sig-line">Employer Signature</div>
                </div>

                <div class="signature-block">
                    <div style="height: 85px;"></div>
                    <div class="sig-line">Employee Signature</div>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <div class="footer">
            <div>
                <strong>ERBIUMTECH (SMC-PRIVATE) LIMITED.</strong><br>
                350/A Khayaban-e-Zafar Housing Scheme<br>
                Pine Avenue Road, 54800 Lahore
            </div>
            <div class="footer-right">
                Phone: +92 302 0606 888<br>
                info@erbium.tech<br>
                www.erbium.tech
            </div>
        </div>
    </div>
</body>

</html>
