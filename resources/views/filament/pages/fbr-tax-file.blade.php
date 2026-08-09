<x-filament-panels::page>
    {{ $this->form }}

    @php($rows = $this->getRows())
    @php($month = $this->data['month'] ?? null)
    @php($businessName = \App\Modules\Core\Models\Company::current()?->name)

    <x-filament::section>
        <x-slot name="heading">FBR Tax File — Employee Withholding Tax (u/s 149)</x-slot>
        <x-slot name="description">
            Salary withholding tax deducted from employees
            @if($month) for <strong>{{ $month }}</strong> @endif.
            Pick the fiscal year and month, then download the FBR monthly details Excel file.
        </x-slot>

        @if($rows->isEmpty())
            <p class="text-gray-400 text-sm">No employee withholding tax found for the selected month.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-gray-700 dark:text-gray-200">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-white/10 text-left">
                            <th class="py-2 pr-4 font-medium">Payment Section</th>
                            <th class="py-2 pr-4 font-medium">TaxPayer_CNIC</th>
                            <th class="py-2 pr-4 font-medium">TaxPayer_Name</th>
                            <th class="py-2 pr-4 font-medium">TaxPayer_City</th>
                            <th class="py-2 pr-4 font-medium">TaxPayer_Address</th>
                            <th class="py-2 pr-4 font-medium">TaxPayer_Status</th>
                            <th class="py-2 pr-4 font-medium">TaxPayer_Business_Name</th>
                            <th class="py-2 pl-4 font-medium text-right">Taxable_Amount</th>
                            <th class="py-2 pl-4 font-medium text-right">Tax_Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($rows as $p)
                            @php($employee = $p->employee)
                            @php($city = trim((string) $employee?->address_line_2) ?: \App\Modules\Payroll\Services\EmployeeWithholdingTaxExport::DEFAULT_CITY)
                            <tr class="border-b border-gray-100 dark:border-white/5">
                                <td class="py-1.5 pr-4">{{ \App\Modules\Payroll\Services\EmployeeWithholdingTaxExport::SALARY_SECTION }}</td>
                                <td class="py-1.5 pr-4">{{ $employee?->nic }}</td>
                                <td class="py-1.5 pr-4">{{ trim((string) $employee?->user?->name) }}</td>
                                <td class="py-1.5 pr-4">{{ $city }}</td>
                                <td class="py-1.5 pr-4">{{ trim((string) $employee?->address_line_1) ?: $city }}</td>
                                <td class="py-1.5 pr-4">{{ \App\Modules\Payroll\Services\EmployeeWithholdingTaxExport::TAXPAYER_STATUS }}</td>
                                <td class="py-1.5 pr-4">{{ $businessName }}</td>
                                <td class="py-1.5 pl-4 text-right tabular-nums">{{ number_format($p->total_earnings*0.9, 2) }}</td>
                                <td class="py-1.5 pl-4 text-right tabular-nums">{{ number_format($p->withholding_tax, 2) }}</td>
                            </tr>
                        @endforeach
                        <tr class="border-t-2 border-gray-300 dark:border-white/20 font-bold">
                            <td colspan="7" class="py-2 pr-4">Total ({{ $rows->count() }} employees)</td>
                            <td class="py-2 pl-4 text-right tabular-nums">{{ number_format($rows->sum('total_earnings')*0.9, 2) }}</td>
                            <td class="py-2 pl-4 text-right tabular-nums">{{ number_format($rows->sum('withholding_tax'), 2) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
