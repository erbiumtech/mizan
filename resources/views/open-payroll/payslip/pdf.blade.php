<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-EVSTQN3/azprG1Anm3QDgpJLIm9Nao0Yz1ztcQTwFspd3yD65VohhpuuCOmLASjC" crossorigin="anonymous">
<div class="modal-body">
    <div class="text-md-end mb-2">
        <a href="#" class="btn btn-lg btn-primary" data-bs-toggle="tooltip" data-bs-placement="bottom"
            title="{{ __('Download') }}" onclick="saveAsPDF()"><span class="fa fa-download"></span>Download</a>
    </div>
    <div class="invoice" id="printableArea">
        <div class="row">
            <div class="col-form-label">
                <div class="invoice-number">
                    Company Logo
                    {{-- <img src="{{ $logo . '/' . (isset($company_logo) && !empty($company_logo) ? $company_logo : 'logo-dark.png') }}"
                        width="170px;" class="img-fluid"> --}}
                </div>
                <div class="invoice-print">
                    <div class="row">
                        <div class="col-lg-12">
                            <div class="invoice-title">
                                <h6 class="mb-3">{{ __('Payslip') }}</h6>
                            </div>
                            <hr>
                            <div class="row text-sm">
                                <div class="col-md-6">
                                    <address>
                                        <strong>{{ __('Name') }} :</strong> {{ $employee->user->full_name }}<br>
                                        <strong>{{ __('Position') }} :</strong> {{ $employee->position ? $employee->position->name : '-' }}<br>
                                        <strong>{{ __('Salary Date') }} :</strong>
                                        {{ date("d-m-Y", strtotime($payslip->payroll->date)) }}<br>
                                    </address>
                                </div>
                                <div class="col-md-6 text-end">
                                    <address>
                                        <strong>{{ $companyName }} </strong><br>
                                        Gota ,
                                        Ahmedabad,<br>
                                        Gujrat-380038<br>
                                        <strong>{{ __('Salary Slip') }} :</strong> {{ \DateTime::createFromFormat('!m', $payslip->payroll->month)->format('F') }}-{{ $payslip->payroll->year }}<br>
                                    </address>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row mt-2">
                        <div class="col-md-12">
                            <div class="table-responsive">
                                <table class="table table-striped table-hover table-md">
                                    <thead class="thead-dark">
                                        <tr>
                                            <th>{{ __('Earning') }}</th>
                                            <th>{{ __('Title') }}</th>
                                            {{-- <th>{{ __('Type') }}</th> --}}
                                            <th class="text-right">{{ __('Amount') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td>{{ __('Basic Salary') }}</td>
                                            <td>-</td>
                                            {{-- <td>-</td> --}}
                                            <td class="text-right">
                                                {{ money()->toHuman($payslip->basic_salary) }}</td>
                                        </tr>
                                        @php
                                            $earningAmount = $deductionAmount = 0;
                                        @endphp
                                        @foreach ($payslip->earnings as $earning)
                                            <tr>
                                                <td>{{ $earning->type->name }}</td>
                                                <td>{{ $earning->name }}</td>
                                                <td>{{ money()->toHuman($earning->amount) }}</td>
                                            </tr>
                                            @php
                                                $earningAmount += $earning->amount;
                                            @endphp
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>

                            <div class="table-responsive">
                                <table class="table table-striped table-hover table-md">
                                    <thead class="thead-dark">
                                        <tr>
                                            <th>{{ __('Deduction') }}</th>
                                            <th>{{ __('Title') }}</th>
                                            {{-- <th>{{ __('type') }}</th> --}}
                                            <th class="text-right">{{ __('Amount') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($payslip->deductions as $deduction)
                                            <tr>
                                                <td>{{ $deduction->type->name }}</td>
                                                <td>{{ $deduction->name ?? '-' }}</td>
                                                <td>{{ money()->toHuman($deduction->amount) }}</td>
                                                @php
                                                    $deductionAmount += $deduction->amount;
                                                @endphp
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>

                            <div class="row mt-4">
                                <div class="col-lg-8">

                                </div>
                                <div class="col-lg-4 text-right text-sm">
                                    <div class="invoice-detail-item pb-2">
                                        <div class="invoice-detail-name font-weight-bold">{{ __('Total Earnings') }}
                                        </div>
                                        <div class="invoice-detail-value">
                                            {{ money()->toHuman($earningAmount + $payslip->basic_salary) }}</div>
                                    </div>
                                    <div class="invoice-detail-item">
                                        <div class="invoice-detail-name font-weight-bold">{{ __('Total Deductions') }}
                                        </div>
                                        <div class="invoice-detail-value">
                                            {{ money()->toHuman($deductionAmount) }}</div>
                                    </div>
                                    <hr class="mt-2 mb-2">
                                    <div class="invoice-detail-item">
                                        <div class="invoice-detail-name font-weight-bold">{{ __('Net Payable Salary') }}</div>
                                        <div class="invoice-detail-value invoice-detail-value-lg">
                                            {{ money()->toHuman($payslip->net_salary) }}</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <hr>
                <div class="text-md-right pb-2 text-sm">
                    <div class="float-lg-left mb-lg-0 mb-3 ">
                        <p class="mt-2">{{ __('Employee Signature') }}</p>
                    </div>
                    <p class="mt-2 "> {{ __('Paid By') }}</p>
                </div>
            </div>
        </div>

    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.9.2/html2pdf.bundle.min.js"></script>
<script>
    function saveAsPDF() {
        var element = document.getElementById('printableArea');
        var opt = {
            margin: 0.3,
            filename: '{{ $employee->name }}',
            image: {
                type: 'jpeg',
                quality: 1
            },
            html2canvas: {
                scale: 4,
                dpi: 72,
                letterRendering: true
            },
            jsPDF: {
                unit: 'in',
                format: 'A4'
            }
        };
        html2pdf().set(opt).from(element).save();
    }
</script>